// @vitest-environment jsdom

import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { FormAlert } from './FormAlert'

describe('FormAlert', () => {
  it('announces and focuses a newly visible general error', () => {
    const view = render(<FormAlert message={null} focusOnShow />)
    expect(screen.queryByRole('alert')).toBeNull()

    view.rerender(<FormAlert message="儲存失敗" focusOnShow />)

    const alert = screen.getByRole('alert')
    expect(alert.textContent).toBe('儲存失敗')
    expect(alert.getAttribute('aria-live')).toBe('off')
    expect(alert.getAttribute('tabindex')).toBe('-1')
    expect(document.activeElement).toBe(alert)
  })

  it('focuses the same error again after a new submit signal', () => {
    const view = render(
      <>
        <FormAlert message="請輸入必要資料" signal={1} focusOnShow />
        <button type="button">再次送出</button>
      </>,
    )
    const alert = screen.getByRole('alert')
    expect(document.activeElement).toBe(alert)

    screen.getByRole('button', { name: '再次送出' }).focus()
    view.rerender(
      <>
        <FormAlert message="請輸入必要資料" signal={2} focusOnShow />
        <button type="button">再次送出</button>
      </>,
    )

    expect(document.activeElement).toBe(alert)
  })

  it('announces a passive error without moving focus', () => {
    render(
      <>
        <input aria-label="搜尋" autoFocus />
        <FormAlert message="列表載入失敗" />
      </>,
    )

    expect(screen.getByRole('alert').textContent).toBe('列表載入失敗')
    expect(screen.getByRole('alert').getAttribute('aria-live')).toBe('assertive')
    expect(document.activeElement).toBe(screen.getByRole('textbox', { name: '搜尋' }))
  })
})
