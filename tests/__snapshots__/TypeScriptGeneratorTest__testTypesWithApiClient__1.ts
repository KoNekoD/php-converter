// THE FILE WAS AUTOGENERATED USING DTO-CONVERTER. PLEASE DO NOT EDIT IT!

import axios from 'axios';

export type CreateUserInput = {
  id: string;
};

export type UpdateUserInput = {
  id: string;
};

export type UserOutput = {
  id: string;
};
export const apiUsersGet = (): Promise<UserOutput[]> => {
  return axios
    .get<UserOutput[]>(`/api/users`)
    .then(response => response.data);
}

export const apiUsersPost = (body: CreateUserInput): Promise<UserOutput> => {
  return axios
    .post<UserOutput>(`/api/users`, body)
    .then(response => response.data);
}

export const apiUsersUserToUpdatePut = (userToUpdate: string, body: UpdateUserInput): Promise<UserOutput> => {
  return axios
    .put<UserOutput>(`/api/users/${userToUpdate}`, body)
    .then(response => response.data);
}

export const apiUsersUserGet = (user: string): Promise<UserOutput> => {
  return axios
    .get<UserOutput>(`/api/users/${user}`)
    .then(response => response.data);
}

