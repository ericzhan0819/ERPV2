import { X } from 'lucide-react'

export function ActiveFilterChip({ label, onRemove }: { label: string; onRemove: () => void }) {
  return (
    <span className="inline-flex min-h-9 max-w-full items-center gap-2 rounded-full border border-border-strong bg-surface-2 px-3 text-sm text-fg">
      <span className="truncate">{label}</span>
      <button
        type="button"
        aria-label={`清除${label}`}
        onClick={onRemove}
        className="shrink-0 rounded-full p-1 text-fg-muted hover:text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        <X aria-hidden className="h-4 w-4" />
      </button>
    </span>
  )
}
