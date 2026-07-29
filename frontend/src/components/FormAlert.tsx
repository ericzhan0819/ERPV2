import { useEffect, useRef } from 'react'

export function FormAlert({
  message,
  signal,
  className = '',
}: {
  message: string | null
  signal?: number
  className?: string
}) {
  const alertRef = useRef<HTMLParagraphElement>(null)

  useEffect(() => {
    if (message) alertRef.current?.focus()
  }, [message, signal])

  if (!message) return null

  return (
    <p
      ref={alertRef}
      role="alert"
      aria-live="assertive"
      tabIndex={-1}
      className={`text-sm text-error ${className}`}
    >
      {message}
    </p>
  )
}
