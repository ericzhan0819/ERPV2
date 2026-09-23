import { useEffect, useRef } from 'react'

export function FormAlert({
  message,
  signal,
  focusOnShow = false,
  className = '',
}: {
  message: string | null
  signal?: number
  focusOnShow?: boolean
  className?: string
}) {
  const alertRef = useRef<HTMLParagraphElement>(null)

  useEffect(() => {
    if (message && focusOnShow) alertRef.current?.focus()
  }, [focusOnShow, message, signal])

  if (!message) return null

  return (
    <p
      ref={alertRef}
      role="alert"
      aria-live={focusOnShow ? 'off' : 'assertive'}
      tabIndex={-1}
      className={`text-sm text-error ${className}`}
    >
      {message}
    </p>
  )
}
