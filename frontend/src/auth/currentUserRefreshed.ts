import type { User } from '../types/auth'
import type { AuthRequestGeneration } from './sessionState'

const listeners = new Set<(user: User, generation: AuthRequestGeneration) => void>()

export function notifyCurrentUserRefreshed(user: User, generation: AuthRequestGeneration) {
  listeners.forEach((listener) => listener(user, generation))
}

export function onCurrentUserRefreshed(listener: (user: User, generation: AuthRequestGeneration) => void) {
  listeners.add(listener)
  return () => { listeners.delete(listener) }
}
