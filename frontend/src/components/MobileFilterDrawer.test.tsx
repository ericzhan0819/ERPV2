// @vitest-environment jsdom

import { useRef, useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { MobileFilterDrawer } from './MobileFilterDrawer'

function DrawerHarness() {
  const [open, setOpen] = useState(false)
  const triggerRef = useRef<HTMLButtonElement>(null)

  return (
    <>
      <button ref={triggerRef} type="button" onClick={() => setOpen(true)}>
        開啟篩選
      </button>
      <MobileFilterDrawer
        id="test-filter"
        title="篩選測試資料"
        open={open}
        triggerRef={triggerRef}
        onClose={() => setOpen(false)}
        footer={<button type="button">套用篩選</button>}
      >
        <label htmlFor="test-search">搜尋</label>
        <input id="test-search" />
      </MobileFilterDrawer>
    </>
  )
}

describe('MobileFilterDrawer accessibility contract', () => {
  it('keeps its title, initial focus, Escape close and trigger focus return', async () => {
    const interaction = userEvent.setup()
    render(<DrawerHarness />)

    const trigger = screen.getByRole('button', { name: '開啟篩選' })
    await interaction.click(trigger)

    const dialog = screen.getByRole('dialog', { name: '篩選測試資料' })
    expect(dialog.getAttribute('aria-modal')).toBe('true')
    expect(document.activeElement).toBe(screen.getByRole('button', { name: '關閉篩選' }))

    fireEvent.keyDown(document, { key: 'Escape' })

    expect(screen.queryByRole('dialog')).toBeNull()
    expect(document.activeElement).toBe(trigger)
  })
})
