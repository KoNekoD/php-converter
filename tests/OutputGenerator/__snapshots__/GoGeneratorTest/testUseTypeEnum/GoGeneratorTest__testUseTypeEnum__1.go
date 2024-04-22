// Code generated by php-converter. DO NOT EDIT.
// Code generated by php-converter. DO NOT EDIT.
// Code generated by php-converter. DO NOT EDIT.

package gen

type ColorEnum int

const (
	RED               ColorEnum = 0
	GREEN             ColorEnum = 1
	BLUE              ColorEnum = 2
	UNIFIED_ENUM_CASE ColorEnum = 111
)

type RoleEnum string

const (
	ADMIN                     RoleEnum = "admin"
	READER                    RoleEnum = "reader"
	EDITOR                    RoleEnum = "editor"
	UNIFIED_ENUM_CASERoleEnum RoleEnum = "one_one_one"
)

type User struct {
	Id         string    `json:"id"`
	ThemeColor ColorEnum `json:"themeColor"`
	Role       RoleEnum  `json:"role"`
}
