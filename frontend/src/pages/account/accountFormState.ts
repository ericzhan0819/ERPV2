import type { CurrentUserProfilePayload } from '../../types/auth'
import type { User, UserRole } from '../../types/user'

export interface AccountProfileForm {
  name: string
  username: string
}

export function accountProfileForm(user: User): AccountProfileForm {
  return {
    name: user.name,
    username: user.username ?? '',
  }
}

export function accountProfilePayload(
  form: AccountProfileForm,
): CurrentUserProfilePayload {
  const username = form.username.trim().toLowerCase()

  return {
    name: form.name.trim(),
    username: username || null,
  }
}

export function accountRoleLabel(role: UserRole): string {
  return {
    admin: '管理員',
    manager: '經理',
    sales: '業務',
  }[role]
}

export function passwordConfirmationError(
  password: string,
  passwordConfirmation: string,
): string | null {
  return password === passwordConfirmation
    ? null
    : '新密碼與確認密碼不一致'
}
