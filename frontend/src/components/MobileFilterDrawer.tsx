import { useEffect, useRef, type ReactNode, type RefObject } from 'react'
import { X } from 'lucide-react'

interface MobileFilterDrawerProps {
  id: string
  title: string
  open: boolean
  triggerRef: RefObject<HTMLButtonElement | null>
  onClose: () => void
  children: ReactNode
  footer: ReactNode
}

export function MobileFilterDrawer({
  id,
  title,
  open,
  triggerRef,
  onClose,
  children,
  footer,
}: MobileFilterDrawerProps) {
  const drawerRef = useRef<HTMLElement>(null)
  const closeRef = useRef<HTMLButtonElement>(null)
  const onCloseRef = useRef(onClose)

  useEffect(() => {
    onCloseRef.current = onClose
  }, [onClose])

  useEffect(() => {
    if (!open) return

    const previousBodyOverflow = document.body.style.overflow
    const drawerTrigger = triggerRef.current
    document.body.style.overflow = 'hidden'
    closeRef.current?.focus()

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        event.preventDefault()
        onCloseRef.current()
        return
      }

      if (event.key !== 'Tab' || !drawerRef.current) return
      const focusableElements = Array.from(
        drawerRef.current.querySelectorAll<HTMLElement>(
          'input:not([disabled]), select:not([disabled]), button:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ),
      )
      const firstElement = focusableElements[0]
      const lastElement = focusableElements.at(-1)

      if (!firstElement || !lastElement) return
      if (!drawerRef.current.contains(document.activeElement)) {
        event.preventDefault()
        firstElement.focus()
      } else if (event.shiftKey && document.activeElement === firstElement) {
        event.preventDefault()
        lastElement.focus()
      } else if (!event.shiftKey && document.activeElement === lastElement) {
        event.preventDefault()
        firstElement.focus()
      }
    }

    function closeAtDesktopBreakpoint() {
      if (drawerRef.current && getComputedStyle(drawerRef.current).display === 'none') onCloseRef.current()
    }

    document.addEventListener('keydown', handleKeyDown)
    window.addEventListener('resize', closeAtDesktopBreakpoint)
    return () => {
      document.removeEventListener('keydown', handleKeyDown)
      window.removeEventListener('resize', closeAtDesktopBreakpoint)
      document.body.style.overflow = previousBodyOverflow
      drawerTrigger?.focus()
    }
  }, [open, triggerRef])

  if (!open) return null

  return (
    <>
      <div aria-hidden="true" className="fixed inset-0 z-40 bg-black/50 sm:hidden" onClick={onClose} />
      <aside
        ref={drawerRef}
        id={id}
        role="dialog"
        aria-modal="true"
        aria-labelledby={`${id}-title`}
        className="fixed inset-y-0 right-0 z-50 flex w-[min(100%,24rem)] flex-col border-l border-border bg-surface shadow-lg sm:hidden"
      >
        <div className="flex min-h-14 items-center justify-between border-b border-border px-4">
          <h2 id={`${id}-title`} className="text-lg font-semibold text-fg">{title}</h2>
          <button
            ref={closeRef}
            type="button"
            aria-label="關閉篩選"
            onClick={onClose}
            className="flex min-h-11 min-w-11 items-center justify-center rounded-lg text-fg-muted hover:bg-surface-2 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-ring"
          >
            <X aria-hidden className="h-5 w-5" />
          </button>
        </div>
        <div className="flex-1 overflow-y-auto p-4">{children}</div>
        <div className="grid grid-cols-2 gap-3 border-t border-border p-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
          {footer}
        </div>
      </aside>
    </>
  )
}
