import { liveApi, type SessionResult } from './api';

export interface EmailLoginInput { email: string; password: string; device_uid: string; device_name: string }
export interface NewPasswordInput { password: string; password_confirmation: string }
export interface EmailOwnerSetupInput extends EmailLoginInput, NewPasswordInput { invitation_token: string; name: string; phone?: string }
export interface CompletedEmailAuth { completed: true }
const emailAuthApi = liveApi.injectEndpoints({ endpoints: (build) => ({
  emailLogin: build.mutation<SessionResult, EmailLoginInput>({ query: body => ({ url: 'auth/email/login', method: 'POST', body }), transformResponse: (response: { data: SessionResult }) => response.data }),
  emailOwnerSetup: build.mutation<SessionResult, EmailOwnerSetupInput>({ query: body => ({ url: 'auth/email/setup', method: 'POST', body }), transformResponse: (response: { data: SessionResult }) => response.data }),
  forgotEmailPassword: build.mutation<{ message: string }, { email: string }>({ query: body => ({ url: 'auth/email/forgot-password', method: 'POST', body }), transformResponse: (response: { data: { message: string } }) => response.data }),
  resetEmailPassword: build.mutation<CompletedEmailAuth, NewPasswordInput & { token: string }>({ query: body => ({ url: 'auth/email/reset-password', method: 'POST', body }), transformResponse: (response: { data: CompletedEmailAuth }) => response.data }),
  activateEmailAccount: build.mutation<CompletedEmailAuth, NewPasswordInput & { token: string; email: string }>({ query: body => ({ url: 'auth/email/activate', method: 'POST', body }), transformResponse: (response: { data: CompletedEmailAuth }) => response.data }),
  enrollEmailCredentials: build.mutation<CompletedEmailAuth, NewPasswordInput & { email: string; current_pin: string }>({ query: body => ({ url: 'auth/email/enrollment', method: 'POST', body }), transformResponse: (response: { data: CompletedEmailAuth }) => response.data }),
}) });
export const { useEmailLoginMutation, useEmailOwnerSetupMutation, useForgotEmailPasswordMutation, useResetEmailPasswordMutation, useActivateEmailAccountMutation, useEnrollEmailCredentialsMutation } = emailAuthApi;
