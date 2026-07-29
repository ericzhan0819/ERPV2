// @vitest-environment jsdom

import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { FormAlert } from './FormAlert'

describe('FormAlert', () => {
  it('announces and focuses a newly visible general error', () => {
    const view = render(<FormAlert message={null} />)
    expect(screen.queryByRole('alert')).toBeNull()

    view.rerender(<FormAlert message="儲存失敗" />)

    const alert = screen.getByRole('alert')
    expect(alert.textContent).toBe('儲存失敗')
    expect(alert.getAttribute('aria-live')).toBe('assertive')
    expect(alert.getAttribute('tabindex')).toBe('-1')
    expect(document.activeElement).toBe(alert)
  })
})
