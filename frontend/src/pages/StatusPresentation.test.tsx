// @vitest-environment jsdom

import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as auditLogsApi from '../api/auditLogs'
import { useAuth } from '../hooks/useAuth'
import { ProtectedRoute } from '../routes/ProtectedRoute'
import { AuditLogList } from './audit-logs/AuditLogList'

vi.mock('../api/auditLogs', () => ({
  listAuditLogs: vi.fn(),
}))

vi.mock('../hooks/useAuth', () => ({
  useAuth: vi.fn(),
}))

describe('Global status presentation', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('keeps the protected loading state short and does not show fake content', () => {
    vi.mocked(useAuth).mockReturnValue({
      user: null,
      loading: true,
      logoutStatus: 'idle',
    } as ReturnType<typeof useAuth>)

    render(
      <ProtectedRoute>
        <p>受保護內容</p>
      </ProtectedRoute>,
    )

    expect(screen.getByText('載入中...')).toBeTruthy()
    expect(screen.queryByText('受保護內容')).toBeNull()
  })

  it('keeps the blocked logout consequence and retry action', async () => {
    const retryLogout = vi.fn().mockResolvedValue(undefined)
    const interaction = userEvent.setup()
    vi.mocked(useAuth).mockReturnValue({
      user: null,
      loading: false,
      logoutStatus: 'blocked',
      retryLogout,
    } as unknown as ReturnType<typeof useAuth>)

    render(
      <ProtectedRoute>
        <p>受保護內容</p>
      </ProtectedRoute>,
    )

    expect(
      screen.getByText('登出尚未完成。為保護資料，後台畫面已關閉。請重試登出；若使用共用電腦，請關閉瀏覽器。'),
    ).toBeTruthy()
    await interaction.click(screen.getByRole('button', { name: '重試登出' }))
    expect(retryLogout).toHaveBeenCalledTimes(1)
    expect(screen.queryByText('受保護內容')).toBeNull()
  })

  it('shows a quiet audit empty state when no filters are active', async () => {
    vi.mocked(auditLogsApi.listAuditLogs).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
    })

    render(<AuditLogList />)

    expect(await screen.findByText('尚無稽核紀錄')).toBeTruthy()
    expect(screen.queryByText('尚無符合條件的稽核紀錄')).toBeNull()
    expect(screen.queryByRole('button', { name: '清除篩選條件' })).toBeNull()
  })

  it('distinguishes an audit filter miss and clears only when there is an actionable filter', async () => {
    const interaction = userEvent.setup()
    vi.mocked(auditLogsApi.listAuditLogs).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
    })

    render(<AuditLogList />)
    await screen.findByText('尚無稽核紀錄')
    fireEvent.change(screen.getByPlaceholderText('搜尋操作者或操作對象'), {
      target: { value: '不存在' },
    })

    expect(await screen.findByText('尚無符合條件的稽核紀錄')).toBeTruthy()
    await interaction.click(screen.getByRole('button', { name: '清除篩選條件' }))
    expect(await screen.findByText('尚無稽核紀錄')).toBeTruthy()
    expect(screen.queryByRole('button', { name: '清除篩選條件' })).toBeNull()
  })
})
