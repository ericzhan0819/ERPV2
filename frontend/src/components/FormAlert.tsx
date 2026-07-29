import { useEffect, useRef } from 'react'

export function FormAlert({
  message,
  className = '',
}: {
  message: string | null
  className?: string
}) {
  const alertRef = useRef<HTMLParagraphElement>(null)

  useEffect(() => {
    if (message) alertRef.current?.focus()
  }, [message])

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
