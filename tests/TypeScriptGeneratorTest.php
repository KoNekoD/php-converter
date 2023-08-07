<?php

declare(strict_types=1);

namespace App\Tests;

use App\Tests\SnapshotComparator\TypeScriptSnapshotComparator;
use PHPUnit\Framework\TestCase;
use Riverwaysoft\PhpConverter\Ast\Converter;
use Riverwaysoft\PhpConverter\Ast\ConverterResult;
use Riverwaysoft\PhpConverter\Ast\DtoVisitor;
use Riverwaysoft\PhpConverter\Bridge\ApiPlatform\ApiPlatformDtoResourceVisitor;
use Riverwaysoft\PhpConverter\Bridge\ApiPlatform\ApiPlatformInputTypeResolver;
use Riverwaysoft\PhpConverter\Bridge\ApiPlatform\AppendCollectionResponseFileProcessor;
use Riverwaysoft\PhpConverter\Bridge\Symfony\SymfonyControllerVisitor;
use Riverwaysoft\PhpConverter\CodeProvider\FileSystemCodeProvider;
use Riverwaysoft\PhpConverter\Filter\PhpAttributeFilter;
use Riverwaysoft\PhpConverter\OutputGenerator\PropertyNameGeneratorInterface;
use Riverwaysoft\PhpConverter\OutputGenerator\TypeScript\TypeScriptGeneratorOptions;
use Riverwaysoft\PhpConverter\OutputGenerator\TypeScript\TypeScriptImportGenerator;
use Riverwaysoft\PhpConverter\OutputGenerator\TypeScript\TypeScriptOptionalPropertyNameGenerator;
use Riverwaysoft\PhpConverter\OutputGenerator\TypeScript\TypeScriptOutputGenerator;
use Riverwaysoft\PhpConverter\OutputGenerator\TypeScript\TypeScriptPropertyNameGenerator;
use Riverwaysoft\PhpConverter\OutputGenerator\TypeScript\TypeScriptTypeResolver;
use Riverwaysoft\PhpConverter\OutputGenerator\UnknownTypeResolver\ClassNameTypeResolver;
use Riverwaysoft\PhpConverter\OutputGenerator\UnknownTypeResolver\DateTimeTypeResolver;
use Riverwaysoft\PhpConverter\OutputWriter\EntityPerClassOutputWriter\DtoTypeDependencyCalculator;
use Riverwaysoft\PhpConverter\OutputWriter\EntityPerClassOutputWriter\EntityPerClassOutputWriter;
use Riverwaysoft\PhpConverter\OutputWriter\EntityPerClassOutputWriter\KebabCaseFileNameGenerator;
use Riverwaysoft\PhpConverter\OutputWriter\OutputProcessor\OutputFilesProcessor;
use Riverwaysoft\PhpConverter\OutputWriter\OutputProcessor\PrependAutogeneratedNoticeFileProcessor;
use Riverwaysoft\PhpConverter\OutputWriter\OutputProcessor\PrependTextFileProcessor;
use Riverwaysoft\PhpConverter\OutputWriter\SingleFileOutputWriter\SingleFileOutputWriter;
use Spatie\Snapshots\MatchesSnapshots;

class TypeScriptGeneratorTest extends TestCase
{
    use MatchesSnapshots;

    public function testNormalizationTsDefault(): void
    {
        $codeAttribute = <<<'CODE'
<?php

class UserCreate {
    /** @var string[] */
    public array $achievements;
    /** @var int[][] */
    public array $matrix;
    public ?string $name;
    public string|int|string|null|null $duplicatesInType;
    public int|string|float $age;
    public bool|null $isApproved;
    public float $latitude;
    public float $longitude;
    public mixed $mixed;
}

class CloudNotify {
    public function __construct(public string $id, public string|null $fcmToken, string $notPublicIgnoreMe)
    {
    }
}

/**
* @template T
 */
class Response {
    /**
    * @param T $data
    */
    public function __construct(
        public $data,
    ) {}
}
CODE;

        $normalized = (new Converter([new DtoVisitor()]))->convert([$codeAttribute]);
        $this->assertMatchesJsonSnapshot($normalized->dtoList->getList());
        $results = (new TypeScriptOutputGenerator(
            outputWriter: new SingleFileOutputWriter('generated.ts'),
            typeResolver: new TypeScriptTypeResolver([]),
            outputFilesProcessor: new OutputFilesProcessor([
                new PrependAutogeneratedNoticeFileProcessor(),
            ]),
            options: null
        ))->generate($normalized);
        $this->assertCount(1, $results);
        $this->assertMatchesSnapshot($results[0]->getContent(), new TypeScriptSnapshotComparator());
    }

    public function testNestedDtoConvert(): void
    {
        $codeNestedDto = <<<'CODE'
<?php

class UserCreate {
    public string $id;
    public ?Profile $profile;
}

class FullName {
    public string $firstName;
    public string $lastName;
}

class Profile {
    public FullName|null|string $name;
    public int $age;
}
CODE;

        $normalized = (new Converter([new DtoVisitor()]))->convert([$codeNestedDto]);
        $results = (new TypeScriptOutputGenerator(
            outputWriter: new SingleFileOutputWriter('generated.ts'),
            typeResolver: new TypeScriptTypeResolver([new ClassNameTypeResolver()]),
            outputFilesProcessor: new OutputFilesProcessor([
                new PrependAutogeneratedNoticeFileProcessor(),
            ])
        ))->generate($normalized);
        $this->assertCount(1, $results);
        $this->assertMatchesSnapshot($results[0]->getContent(), new TypeScriptSnapshotComparator());
    }

    public function testUseTypeOverEnumTs(): void
    {
        $code = <<<'CODE'
<?php

use MyCLabs\Enum\Enum;

final class ColorEnum extends Enum
{
    private const RED = 0;
    private const GREEN = 1;
    private const BLUE = 2;
}

final class RoleEnum extends Enum
{
    private const ADMIN = 'admin';
    private const READER = 'reader';
    private const EDITOR = 'editor';
}

class User
{
    public string $id;
    public ColorEnum $themeColor;
    public RoleEnum $role;
}
CODE;

        $normalized = (new Converter([new DtoVisitor()]))->convert([$code]);
        $typeScriptGenerator = new TypeScriptOutputGenerator(
            new SingleFileOutputWriter('generated.ts'),
            new TypeScriptTypeResolver([new ClassNameTypeResolver()]),
            new OutputFilesProcessor([
                new PrependAutogeneratedNoticeFileProcessor(),
            ]),
            [],
            new TypeScriptGeneratorOptions(useTypesInsteadOfEnums: true),
        );
        $results = $typeScriptGenerator->generate($normalized);
        $this->assertCount(1, $results);
        $this->assertMatchesSnapshot($results[0]->getContent(), new TypeScriptSnapshotComparator());
    }

    public function testNormalizationDirectory(): void
    {
        $converter = new Converter([new DtoVisitor()]);
        $fileProvider = new FileSystemCodeProvider('/\.php$/');
        $result = $converter->convert($fileProvider->getListings(__DIR__ . '/Fixtures'));
        $this->assertMatchesJsonSnapshot($result->dtoList->getList());
        $results = (new TypeScriptOutputGenerator(
            new SingleFileOutputWriter('generated.ts'),
            new TypeScriptTypeResolver([new ClassNameTypeResolver()]),
            new OutputFilesProcessor([new PrependAutogeneratedNoticeFileProcessor()]),
        )
        )->generate($result);
        $this->assertCount(1, $results);
        $this->assertMatchesSnapshot($results[0]->getContent(), new TypeScriptSnapshotComparator());
    }

    public function testNormalizationWithCustomTypeResolvers(): void
    {
        $codeWithDateTime = <<<'CODE'
<?php

class UserCreate
{
    public \DateTimeImmutable $createdAt;
    public DateTime $updatedAt;
    public ?DateTimeImmutable $promotedAt;
}

class UserCreateConstructor
{
    public function __construct(
       public DateTimeImmutable $createdAt,
       public \DateTime $updatedAt,
       public ?\DateTimeImmutable $promotedAt,
    )
    {
    
    }
}
CODE;

        $converter = new Converter([new DtoVisitor()]);
        $result = $converter->convert([$codeWithDateTime]);
        $typeScriptGenerator = new TypeScriptOutputGenerator(
            new SingleFileOutputWriter('generated.ts'),
            new TypeScriptTypeResolver([new ClassNameTypeResolver(), new DateTimeTypeResolver()]),
            new OutputFilesProcessor([
                new PrependAutogeneratedNoticeFileProcessor(),
            ])
        );
        $results = ($typeScriptGenerator)->generate($result);
        $this->assertCount(1, $results);
        $this->assertMatchesSnapshot($results[0]->getContent(), new TypeScriptSnapshotComparator());
    }

    public function testEntityPerClassOutputWriterTypeScript(): void
    {
        $codeNestedDto = <<<'CODE'
<?php

class UserCreate {
    public string $id;
    public ?Profile $profile;
}

class FullName {
    public string $firstName;
    public string $lastName;
}

class Profile {
    public FullName|null|string $name;
    public int $age;
}
CODE;

        $normalized = (new Converter([new DtoVisitor()]))->convert([$codeNestedDto]);

        $fileNameGenerator = new KebabCaseFileNameGenerator('.ts');
        $typeScriptGenerator = new TypeScriptOutputGenerator(
            new EntityPerClassOutputWriter(
                $fileNameGenerator,
                new TypeScriptImportGenerator(
                    $fileNameGenerator,
                    new DtoTypeDependencyCalculator()
                )
            ),
            new TypeScriptTypeResolver([new ClassNameTypeResolver(), new DateTimeTypeResolver()]),
        );
        $results = $typeScriptGenerator->generate($normalized);

        $this->assertCount(3, $results);
        $this->assertMatchesSnapshot($results);
    }

    public function testApiPlatformInput(): void
    {
        $codeWithDateTime = <<<'CODE'
<?php

use MyCLabs\Enum\Enum;

#[\Attribute(\Attribute::TARGET_CLASS)]
class Dto
{

}

#[Dto]
enum ColorEnum
{
    case RED = 0;
    case GREEN = 1;
    case BLUE = 2;
}

#[Dto]
final class GenderEnum extends Enum
{
    private const UNKNOWN = null;
    private const MAN = 0;
    private const WOMAN = 1;
}

class Profile
{
    public string $firstName;
    public string $lastName;
}

#[Dto]
class ProfileOutput
{
    public string $firstName;
    public string $lastName;
    public GenderEnum $gender;
    public ColorEnum $color;
}

class LocationEmbeddable {
  public function __construct(
    private float $lat,
    private $lan,
  ) {}
}

class Money {

}

class Industry {}

#[Dto]
class UserCreateInput
{
    /* The time when the user was promoted */
    public Profile $profile;
    // The time when the user was promoted
    public ?DateTimeImmutable $promotedAt;
    public ColorEnum $userTheme;
    /** @var Industry[]|null  */
    public array|null $industriesUnion = null;
    /** @var Industry[]|null  */
    public ?array $industriesNullable = null;
    public Money $money;
    public GenderEnum $gender;
    public LocationEmbeddable $location;
    /** @var (int|string|null)[] */
    public array $mixedArray;
}

CODE;

        $converter = new Converter([new DtoVisitor(new PhpAttributeFilter('Dto'))]);
        $result = $converter->convert([$codeWithDateTime]);
        $typeScriptGenerator = new TypeScriptOutputGenerator(
            new SingleFileOutputWriter('generated.ts'),
            new TypeScriptTypeResolver([
                new DateTimeTypeResolver(),
                new ApiPlatformInputTypeResolver([
                    'LocationEmbeddable' => '{ lat: string; lan: string }',
                    'Money' => '{ currency: string; amount: number }',
                ]),
                new ClassNameTypeResolver(),
            ]),
            new OutputFilesProcessor([
                new PrependAutogeneratedNoticeFileProcessor(),
            ])
        );
        $results = ($typeScriptGenerator)->generate($result);
        $this->assertCount(1, $results);
        $this->assertMatchesSnapshot($results[0]->getContent(), new TypeScriptSnapshotComparator());

        // use TS template literal
        $typeScriptGenerator = new TypeScriptOutputGenerator(
            new SingleFileOutputWriter('generated.ts'),
            new TypeScriptTypeResolver([
                new DateTimeTypeResolver(),
                new ApiPlatformInputTypeResolver([
                    'LocationEmbeddable' => '{ lat: string; lan: string }',
                    'Money' => '{ currency: string; amount: number }',
                ], true, true),
                new ClassNameTypeResolver(),
            ]),
            new OutputFilesProcessor([
                new PrependAutogeneratedNoticeFileProcessor(),
            ]),
        );
        $results = ($typeScriptGenerator)->generate($result);
        $this->assertCount(1, $results);
        $this->assertMatchesSnapshot($results[0]->getContent(), new TypeScriptSnapshotComparator());
    }

    public function testUnknownTypeThrows(): void
    {
        $codeWithDateTime = <<<'CODE'
<?php

#[\Attribute(\Attribute::TARGET_CLASS)]
class Dto
{

}

#[Dto]
class A
{
    public \DateTimeImmutable $createdAt;
    public B $b;
}

class B {}
CODE;

        $converter = new Converter([new DtoVisitor(new PhpAttributeFilter('Dto'))]);
        $result = $converter->convert([$codeWithDateTime]);
        $typeScriptGenerator = new TypeScriptOutputGenerator(
            new SingleFileOutputWriter('generated.ts'),
            new TypeScriptTypeResolver([new ClassNameTypeResolver(), new DateTimeTypeResolver()])
        );

        $this->expectExceptionMessage('PHP Type B is not supported. PHP class: A');
        $typeScriptGenerator->generate($result);
    }

    public function testDtoConstantDoesntThrow(): void
    {
        $codeWithDateTime = <<<'CODE'
<?php

#[Dto]
class A
{
    public const SOME_CONSTANT = 1;
    public \DateTimeImmutable $createdAt;
}

#[Dto]
final class GenderEnum extends Enum
{
    public const UNKNOWN = null;
    private const MAN = 0;
    private const WOMAN = 1;
}

CODE;

        $converter = new Converter([new DtoVisitor(new PhpAttributeFilter('Dto'))]);
        $result = $converter->convert([$codeWithDateTime]);
        $typeScriptGenerator = new TypeScriptOutputGenerator(
            new SingleFileOutputWriter('generated.ts'),
            new TypeScriptTypeResolver([new ClassNameTypeResolver(), new DateTimeTypeResolver()])
        );

        $results = $typeScriptGenerator->generate($result);

        $this->assertMatchesSnapshot($results[0]->getContent(), new TypeScriptSnapshotComparator());
    }

    public function testPhp81SuccessWhenBacked(): void
    {
        $codeWithDateTime = <<<'CODE'
<?php

#[\Attribute(\Attribute::TARGET_CLASS)]
class Dto
{

}

#[Dto]
enum Color: int
{
    case RED = 0;
    case BLUE = 1;
    case WHITE = 2;
}

#[Dto]
enum Role: string
{
    case ADMIN = 'admin';
    case EDITOR = 'editor';
    case READER = 'reader';
}

#[Dto]
class User {
    public function __construct(public Color $color, public readonly int $user, public Role $role)
    {

    }

    public function getColor(): Color
    {
        return $this->color;
    }

    public function getUser(): int
    {
        return $this->user;
    }
}
CODE;

        $converter = new Converter([new DtoVisitor(new PhpAttributeFilter('Dto'))]);
        $result = $converter->convert([$codeWithDateTime]);

        $typeScriptGenerator = new TypeScriptOutputGenerator(
            new SingleFileOutputWriter('generated.ts'),
            new TypeScriptTypeResolver([
                new ClassNameTypeResolver(),
            ]),
            new OutputFilesProcessor([
                new PrependAutogeneratedNoticeFileProcessor(),
            ])
        );
        $results = ($typeScriptGenerator)->generate($result);
        $this->assertCount(1, $results);
        $this->assertMatchesSnapshot($results[0]->getContent(), new TypeScriptSnapshotComparator());
    }

    public function testTypesWithApiClient(): void
    {
        $codeWithDateTime = <<<'CODE'
<?php

use Riverwaysoft\PhpConverter\Filter\Attributes\Dto;use Riverwaysoft\PhpConverter\Filter\Attributes\DtoEndpoint;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Route {
  public function __construct(
     public string|array $path = null,
     public array|string $methods = [],
  ) {}
}

#[\Attribute(\Attribute::TARGET_PARAMETER)]
class Input {
  
}

#[\Attribute(\Attribute::TARGET_PARAMETER)]
class Query {
  
}

#[Dto]
class FilterQuery {
  public function __construct(
    public int|null $age,
    public string|null $name = null,
  ) {
  }
}

#[Dto]
class UserOutput {
    public function __construct(public string $id, public string $hasDefaultValue = '')
    {
    }
}

#[Dto]
class CreateUserInput {
    public function __construct(public string $id)
    {
    }
}

#[Dto]
class UpdateUserInput {
    public function __construct(public string $id)
    {
    }
}

class UserController {
  /** @return UserOutput[] */
  #[DtoEndpoint()]
  #[Route('/api/users', methods: ['GET'])]
  public function getUsers() {}
  
  /** @return UserOutput */
  #[DtoEndpoint()]
  #[Route('/api/users/{user}', methods: ['GET'])]
  public function getUser(User $user) {}
  
  /** @return UserOutput */
  #[DtoEndpoint()]
  #[Route('/api/users', methods: ['POST'])]
  public function getUser(#[Input] CreateUserInput $input) {}
  
  /** @return UserOutput */
  #[DtoEndpoint()]
  #[Route('/api/users_update-it/{userToUpdate}', methods: ['PUT'])]
  public function getUser(User $userToUpdate, #[Input] UpdateUserInput $input) {}
  
  /** @return UserOutput[] */
  #[DtoEndpoint()]
  #[Route('/api/users-with-filters', methods: ['GET'])]
  public function getUsersWithFilters(#[Query] FilterQuery $query) {}
  
  #[DtoEndpoint()]
  #[Route(path: '/api/route-with-path', methods: ['GET'])]
  public function routeWithPath() {}
  
  #[DtoEndpoint()]
  #[Route(name: '/api/route-with-name', methods: ['GET'])]
  public function routeWithName() {}
  
  /** @return UserOutput */
  #[DtoEndpoint()]
  #[Route(name: '/api/route-with-annotations-return', methods: ['GET'])]
  public function routeWithAnnotationsReturn() {}
}
CODE;

        $converter = new Converter([
            new DtoVisitor(new PhpAttributeFilter('Dto')),
            new SymfonyControllerVisitor(new PhpAttributeFilter('DtoEndpoint')),
        ]);
        $result = $converter->convert([$codeWithDateTime]);
        $this->assertCount(8, $result->apiEndpointList->getList());
        $this->assertMatchesGeneratedTypeScriptApi($result, [
            new TypeScriptOptionalPropertyNameGenerator(),
            new TypeScriptPropertyNameGenerator(),
        ]);
    }

    public function testTypesWithApiClientAndOverrideReturn(): void
    {
        $codeWithDateTime = <<<'CODE'
<?php

use Riverwaysoft\PhpConverter\Filter\Attributes\Dto;use Riverwaysoft\PhpConverter\Filter\Attributes\DtoEndpoint;


/**
* @template T
 */
 #[Dto]
class JsonResponse {
    /**
    * @param T $data
    */
    public function __construct(
        public $data,
    ) {}
}

#[\Attribute(\Attribute::TARGET_METHOD)]
class Route {
  public function __construct(
     public string|array $path = null,
     public array|string $methods = [],
  ) {}
}

#[Dto]
class UserOutput {
    public function __construct(public string $id)
    {
    }
}

#[Dto]
class UserShortOutput {
    public function __construct(public string $id)
    {
    }
}

class UserController {
   /** @return UserOutput */
  #[DtoEndpoint()]
  #[Route(name: '/api/annotations-return', methods: ['GET'])]
  public function annotationsReturn() {}
  
  /** @return UserShortOutput */
  #[DtoEndpoint()]
  #[Route(name: '/api/annotations-return-precedence', methods: ['GET'])]
  public function annotationsReturnTakePrecedenceOverDtoEndpoint() {}
  
  /** @return JsonResponse<UserOutput> */
  #[DtoEndpoint()]
  #[Route(name: '/api/route-with-nested-generics-annotations-return', methods: ['GET'])]
  public function nestedGenericAnnotationReturn() {}
  
  /** @return JsonResponse<UserOutput[]> */
  #[DtoEndpoint()]
  #[Route(name: '/api/route-with-nested-generics-union-annotations-return', methods: ['GET'])]
  public function nestedGenericAnnotationUnionReturn() {}
  
    /** @return JsonResponse<string[]> */
  #[DtoEndpoint()]
  #[Route(name: '/api/nested-generics-simple-type', methods: ['GET'])]
  public function nestedGenericsSimpleType() {}
}
CODE;

        $converter = new Converter([
            new DtoVisitor(new PhpAttributeFilter('Dto')),
            new SymfonyControllerVisitor(new PhpAttributeFilter('DtoEndpoint')),
        ]);
        $result = $converter->convert([$codeWithDateTime]);
        $this->assertCount(5, $result->apiEndpointList->getList());
        $this->assertMatchesGeneratedTypeScriptApi($result);
    }

    public function testGenericDtoWithArrayField(): void
    {
        $code = <<<'CODE'
<?php

use Riverwaysoft\PhpConverter\Filter\Attributes\Dto;

/**
 * @template T
 */
#[Dto]
class Paginated
{
    /**
     * @param T[] $items
     */
    public function __construct(
        public array $items,
        public int $totalCount,
        public int $pagesCount,
        public int $page,
    )
    {
    }
}
CODE;

        $converter = new Converter([
            new DtoVisitor(new PhpAttributeFilter('Dto')),
        ]);
        $result = $converter->convert([$code]);
        $this->assertMatchesGeneratedTypeScriptApi($result);
    }

    public function testApiClientGenerationWithApiPlatformLegacyResource(): void
    {
        $code = <<<'CODE'
<?php

use Riverwaysoft\PhpConverter\Bridge\ApiPlatform\DtoResource;use Riverwaysoft\PhpConverter\Filter\Attributes\Dto;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
class Input {
  
}

#[Dto]
class AdminZoneChatOutput {
  public function __construct(
    public string $id,
    public bool $isAdmin,
  ) {
  }
}

#[Dto]
class AdminZoneChatCreateInput {
  public function __construct(
    public string $id,
    public bool $isAdmin,
  ) {
  }
}

#[Dto]
class ChatOutput {
  public function __construct(
    public string $id,
  ) {
  }
}

#[Dto]
class ChatMessageWithAttachmentsOutput {

}

#[Dto]
class AdminZoneChatUpdateInput {}


#[\Attribute(\Attribute::TARGET_CLASS)]
class ApiResource {
// Copied from API Platform source
  public function __construct(
        $description = null,
        ?array $collectionOperations = null,
        ?string $iri = null,
        ?array $itemOperations = null,
        ?string $shortName = null,
        ?array $subresourceOperations = null,
        ?array $cacheHeaders = null,
        ?string $deprecationReason = null,
        ?bool $elasticsearch = null,
        ?bool $fetchPartial = null,
        ?bool $forceEager = null,
        ?array $formats = null,
        ?array $filters = null,
        ?array $hydraContext = null,
        $input = null,
        ?array $openapiContext = null,
        ?array $order = null,
        $output = null,
        ?string $routePrefix = null,
  ) {}
}

#[ApiResource(
    collectionOperations: [
        "get" => ["security" => "is_granted('ROLE_CHAT_LIST_ACCESS')"],
        "admin_get" => [
            "path" => "/chats_admin_zone",
            "method" => "GET",
            'output' => AdminZoneChatOutput::class,
        ],
        "admin_create" => [
            "path" => "/chats_admin_zone",
            "method" => 'POST',
            'input' => AdminZoneChatCreateInput::class,
            'output' => AdminZoneChatOutput::class,
        ]
    ],
    itemOperations: [
        "get" => ["security" => "is_granted('ROLE_CHAT_ITEM_ACCESS', object)"],
        "chat_messages_with_attachments" => ["method" => "GET", "output" => ChatMessageWithAttachmentsOutput::class, "path" => "/chats/{id}/messages_with_attachments", "controller" => ChatMessageAttachmentsController::class],
        "mark_as_read" => [
            "path" => "/chats/{id}/mark_as_read",
            "method" => "PUT",
            "input" => false,
            "controller" => MarkChatAsReadAction::class,
            "security" => "is_granted('ROLE_CHAT_ITEM_ACCESS', object)"
        ],
        "mute" => [
            'path' => '/chats/{id}/mute',
            'method' => 'PUT',
            'input' => false,
            "controller" => MuteChatController::class,
            "security" => "is_granted('ROLE_CHAT_ITEM_ACCESS', object)"
        ],
        "admin_update" => [
            "path" => '/chats_admin_zone/{id}',
            'method' => 'PUT',
            'input' => AdminZoneChatUpdateInput::class,
            'output' => AdminZoneChatOutput::class,
        ],
        "admin_get" => [
            "path" => '/chats_admin_zone/{id}',
            'method' => 'GET',
            'output' => AdminZoneChatOutput::class,
        ]
    ],
    output: ChatOutput::class
)]
#[DtoResource]
class Chat
{}

#[Dto]
class StudentNotesUpdateInput {}
#[Dto]
class StudentNotesInput {}
#[Dto]
class StudentNotesOutput {
public string $id;
}

#[ApiResource(
    collectionOperations: [
        'get',
        'post'
    ],
    itemOperations: [
        'get',
        'put' => [
            'input' => StudentNotesUpdateInput::class
        ],
    ],
    attributes: ['order' => ['createdAt' => 'DESC']],
    input: StudentNotesInput::class,
    normalizationContext: ["jsonld_has_context" => false],
    output: StudentNotesOutput::class,
)]
#[DtoResource]
class StudentNotes {}

#[Dto]
class CloudPushStoreOutput {}

#[ApiResource(
    itemOperations: ['get'],
    shortName: "push_history_item",
    output: CloudPushStoreOutput::class
)]
#[DtoResource]
class CloudPushStore {}

CODE;

        $converter = new Converter([
            new DtoVisitor(new PhpAttributeFilter('Dto')),
            new ApiPlatformDtoResourceVisitor(new PhpAttributeFilter('DtoResource')),
        ]);

        $result = $converter->convert([$code]);

        $this->assertCount(14, $result->apiEndpointList->getList());

        $this->assertMatchesGeneratedTypeScriptApi($result);
    }

    public function testApiPlatformWithNewApiResource(): void
    {
        $code = <<<'CODE'
<?php

use Riverwaysoft\PhpConverter\Bridge\ApiPlatform\DtoResource;use Riverwaysoft\PhpConverter\Filter\Attributes\Dto;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
class Input {
  
}

#[\Attribute(\Attribute::TARGET_CLASS)]
class ApiResource {
// Copied from API Platform source
     public function __construct(
        ?string $uriTemplate = null,
        ?string $shortName = null,
        ?string $description = null,
        $types = null,
        $operations = null,
        $formats = null,
        $inputFormats = null,
        $outputFormats = null,
        $uriVariables = null,
        ?string $routePrefix = null,
        ?array $defaults = null,
        ?array $requirements = null,
        ?array $options = null,
        ?int $status = null,
        ?string $host = null,
        ?array $schemes = null,
        ?string $controller = null,
        ?string $class = null,
        ?int $urlGenerationStrategy = null,
        ?array $filters = null,
        ?bool $elasticsearch = null,
        $mercure = null,
        $messenger = null,
        $input = null,
        $output = null,
        ?array $order = null,
        ?string $paginationType = null,
        ?string $security = null,
        ?string $securityMessage = null,
        ?string $securityPostDenormalize = null,
        ?string $securityPostDenormalizeMessage = null,
        ?string $securityPostValidation = null,
        ?string $securityPostValidationMessage = null,
        ?bool $compositeIdentifier = null,
        ?array $exceptionToStatus = null,
        ?bool $queryParameterValidationEnabled = null,
        ?array $graphQlOperations = null,
        $provider = null,
        $processor = null,
        array $extraProperties = []
        ) {}
}

#[Dto]
class HubUserOutput
{
    public function __construct(
        public string $id,
        public string $username,
    ) {}
}

#[Dto]
class HubUserUpdateInput
{
    public function __construct(
        public string $username,
    ) {}
}

#[Dto]
class HubUserCreateInput
{
    public function __construct(
        public string $id,
        public string $username,
    ) {}
}

#[Dto]
class BranchContextUpdateInput {}

#[ApiResource(
    operations: [
        new Put(uriTemplate: '/hub_users/{id}/update_branch_context', status: 204, input: BranchContextUpdateInput::class, output: false, processor: HubUserUpdateContextProcessor::class),
        new Put(input: HubUserUpdateInput::class),
        new Get(),
        new Delete(),
        new GetCollection(),
        new Post(input: HubUserCreateInput::class)
    ],
    output: HubUserOutput::class,
)]
#[DtoResource]
class HubUser {}

#[Dto]
class FullBookingOutput
{
    public function __construct(
        public string $id,
    ) {}
}

#[ApiResource(
    operations: [
        new GetCollection(),
    ],
    output: FullBookingOutput::class,
)]
#[ApiResource(
    uriTemplate: '/customer_sites/{id}/bookings.{_format}',
    operations: [new GetCollection()],
    uriVariables: ['id' => new Link(fromClass: CustomerSite::class, identifiers: ['id'])],
    output: FullBookingOutput::class,
)
]
#[DtoResource]
class Booking {}


#[Dto]
class JobSheetTagCollectionOutput {}

#[ApiResource(
    uriTemplate: '/job_sheets/{id}/tags.{_format}',
    operations: [new Get()],
    uriVariables: [
        'id' => new Link(fromProperty: 'tagCollection', fromClass: 'App\Modules\Workshop\JobSheet\Entity\JobSheet'),
    ],
    output: JobSheetTagCollectionOutput::class
)]
#[DtoResource]
class TagCollection {}

#[Dto]
class UserOutput {}

#[ApiResource(
    operations: [new Get(output: UserOutput::class)],
)]
#[DtoResource]
class User {}
CODE;

        $converter = new Converter([
            new DtoVisitor(new PhpAttributeFilter('Dto')),
            new ApiPlatformDtoResourceVisitor(new PhpAttributeFilter('DtoResource')),
        ]);

        $result = $converter->convert([$code]);

        $this->assertCount(10, $result->apiEndpointList->getList());

        $this->assertMatchesGeneratedTypeScriptApi($result);
    }

    public function testApiPlatformModernAtLeastOneOutputIsRequired(): void
    {
        $code = <<<'CODE'
<?php

use Riverwaysoft\PhpConverter\Bridge\ApiPlatform\DtoResource;

#[\Attribute(\Attribute::TARGET_CLASS)]
class ApiResource {
// Copied from API Platform source
     public function __construct(
        ?string $uriTemplate = null,
        ?string $shortName = null,
        ?string $description = null,
        $types = null,
        $operations = null,
        $formats = null,
        $inputFormats = null,
        $outputFormats = null,
        $uriVariables = null,
        ?string $routePrefix = null,
        ?array $defaults = null,
        ?array $requirements = null,
        ?array $options = null,
        ?int $status = null,
        ?string $host = null,
        ?array $schemes = null,
        ?string $controller = null,
        ?string $class = null,
        ?int $urlGenerationStrategy = null,
        ?array $filters = null,
        ?bool $elasticsearch = null,
        $mercure = null,
        $messenger = null,
        $input = null,
        $output = null,
        ?array $order = null,
        ?string $paginationType = null,
        ?string $security = null,
        ?string $securityMessage = null,
        ?string $securityPostDenormalize = null,
        ?string $securityPostDenormalizeMessage = null,
        ?string $securityPostValidation = null,
        ?string $securityPostValidationMessage = null,
        ?bool $compositeIdentifier = null,
        ?array $exceptionToStatus = null,
        ?bool $queryParameterValidationEnabled = null,
        ?array $graphQlOperations = null,
        $provider = null,
        $processor = null,
        array $extraProperties = []
        ) {}
}

#[ApiResource(
    operations: [new Get()],
)]
#[DtoResource]
class User {}
CODE;

        $converter = new Converter([
            new DtoVisitor(new PhpAttributeFilter('Dto')),
            new ApiPlatformDtoResourceVisitor(new PhpAttributeFilter('DtoResource')),
        ]);

        $this->expectExceptionMessage('The output is required for ApiResource User. Context: ApiResource(operations: [new Get()])');
        $result = $converter->convert([$code]);
    }

    public function testApiPlatformLegacyAtLeastOneOutputIsRequired(): void
    {
        $code = <<<'CODE'
<?php

use Riverwaysoft\PhpConverter\Bridge\ApiPlatform\DtoResource;use Riverwaysoft\PhpConverter\Filter\Attributes\Dto;

#[\Attribute(\Attribute::TARGET_CLASS)]
class ApiResource {
// Copied from API Platform source
  public function __construct(
        $description = null,
        ?array $collectionOperations = null,
        ?string $iri = null,
        ?array $itemOperations = null,
        ?string $shortName = null,
        ?array $subresourceOperations = null,
        ?array $cacheHeaders = null,
        ?string $deprecationReason = null,
        ?bool $elasticsearch = null,
        ?bool $fetchPartial = null,
        ?bool $forceEager = null,
        ?array $formats = null,
        ?array $filters = null,
        ?array $hydraContext = null,
        $input = null,
        ?array $openapiContext = null,
        ?array $order = null,
        $output = null,
        ?string $routePrefix = null,
  ) {}
}

#[Dto]
class StudentNotesOutput {
public string $id;
}

#[ApiResource(
    collectionOperations: [
        'post' => ['output' => StudentNotesOutput::class],
        'get' => ['output' => StudentNotesOutput::class]
    ],
    itemOperations: [
        'put' => [
            'input' => StudentNotesUpdateInput::class
        ],
    ],
    attributes: ['order' => ['createdAt' => 'DESC']],
)]
#[DtoResource]
class StudentNotes {}


CODE;

        $converter = new Converter([
            new DtoVisitor(new PhpAttributeFilter('Dto')),
            new ApiPlatformDtoResourceVisitor(new PhpAttributeFilter('DtoResource')),
        ]);

        $this->expectExceptionMessage("The output is required for ApiResource StudentNotes. Context: ApiResource(collectionOperations: ['post' => ['output' => StudentNotesOutput::class], 'get' => ['output' => StudentNotesOutput::class]], itemOperations: ['put' => ['input' => StudentNotesUpdateInput::class]], attributes: ['order' => ['createdAt' => 'DESC']])");
        $result = $converter->convert([$code]);
    }

    /**
     * @param PropertyNameGeneratorInterface[] $propertyNameGenerators
     */
    private function assertMatchesGeneratedTypeScriptApi(ConverterResult $result, array $propertyNameGenerators = []): void
    {
        $typeScriptGenerator = new TypeScriptOutputGenerator(
            new SingleFileOutputWriter('generated.ts'),
            new TypeScriptTypeResolver([
                new ClassNameTypeResolver(),
            ]),
            new OutputFilesProcessor([
                new PrependTextFileProcessor("import axios from 'axios';\n\n"),
                new PrependAutogeneratedNoticeFileProcessor(),
                new AppendCollectionResponseFileProcessor(),
            ]),
            $propertyNameGenerators,
            new TypeScriptGeneratorOptions(
                useTypesInsteadOfEnums: false,
            )
        );
        $results = ($typeScriptGenerator)->generate($result);
        $this->assertCount(1, $results);
        $this->assertMatchesSnapshot($results[0]->getContent(), new TypeScriptSnapshotComparator());
    }
}
