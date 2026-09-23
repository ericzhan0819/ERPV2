import { useEffect, useRef, useState } from 'react'

interface DebouncedSearchInputProps {
  id: string
  value: string
  label: string
  placeholder: string
  onCommit: (value: string) => void
  className?: string
}

export function DebouncedSearchInput({
  id,
  value,
  label,
  placeholder,
  onCommit,
  className = '',
}: DebouncedSearchInputProps) {
  const [draft, setDraft] = useState(value)
  const [isComposing, setIsComposing] = useState(false)
  const onCommitRef = useRef(onCommit)

  useEffect(() => {
    onCommitRef.current = onCommit
  }, [onCommit])

  useEffect(() => {
    setDraft(value)
  }, [value])

  useEffect(() => {
    if (isComposing || draft === value) return

    const timeout = window.setTimeout(() => onCommitRef.current(draft), 275)
    return () => window.clearTimeout(timeout)
  }, [draft, isComposing, value])

  return (
    <label htmlFor={id} className="flex flex-col gap-1 text-sm font-medium text-fg-muted">
      {label}
      <input
        id={id}
        type="search"
        placeholder={placeholder}
        value={draft}
        onChange={(event) => setDraft(event.target.value)}
        onCompositionStart={() => setIsComposing(true)}
        onCompositionEnd={(event) => {
          setDraft(event.currentTarget.value)
          setIsComposing(false)
        }}
        className={className}
      />
    </label>
  )
}
