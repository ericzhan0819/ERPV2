// @vitest-environment jsdom

import { act, fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import * as auditLogsApi from '../api/auditLogs'
import * as customersApi from '../api/customers'
import * as vehiclesApi from '../api/vehicles'
import { CustomerSelect } from '../components/CustomerSelect'
import { useAuth } from '../hooks/useAuth'
import type { AuditLog } from '../types/auditLog'
import type { CustomerDetailResponse } from '../types/customer'
import type { User } from '../types/user'
import type { Vehicle, VehiclePrintClosingResponse, VehiclePrintIntakeResponse } from '../types/vehicle'
import { AuditLogList } from './audit-logs/AuditLogList'
import { CustomerDetail } from './customers/CustomerDetail'
import { CustomerList } from './customers/CustomerList'
import { VehicleClosingPrint } from './print/VehicleClosingPrint'
import { VehicleIntakePrint } from './print/VehicleIntakePrint'

vi.mock('../api/auditLogs', () => ({
  listAuditLogs: vi.fn(),
}))

vi.mock('../api/customers', () => ({
  createCustomer: vi.fn(),
  deleteCustomer: vi.fn(),
  getCustomer: vi.fn(),
  listCustomers: vi.fn(),
  updateCustomer: vi.fn(),
}))

vi.mock('../api/vehicles', () => ({
  getVehiclePrintClosing: vi.fn(),
  getVehiclePrintIntake: vi.fn(),
}))

vi.mock('../hooks/useAuth', () => ({
  useAuth: vi.fn(),
}))

const admin: User = {
  id: 1,
  name: '管理員',
  email: 'admin@example.com',
  username: 'admin',
  must_change_password: false,
  role: 'admin',
  is_admin: true,
  is_active: true,
  phone: null,
  job_title: null,
  hire_date: null,
  notes: null,
}

const vehicle: Vehicle = {
  id: 7,
  stock_no: 'V2026070001',
  status: 'sold',
  brand: 'Toyota',
  model: 'Corolla',
  year: 2022,
  license_plate: 'ABC-1234',
  vin: 'VIN123',
  mileage_km: 12000,
  color: '白',
  displacement: '1800cc',
  transmission: '自排',
  fuel_type: '汽油',
  parking_location: 'A1',
  has_registration_document: true,
  has_spare_key: true,
  is_transfer_completed: true,
  is_inspection_completed: true,
  is_preparation_completed: true,
  lien_note: null,
  condition_note: null,
  purchase_date: '2026-07-01',
  purchase_source_type: '一般收購',
  seller_name: '原車主',
  seller_phone: '0912345678',
  seller_customer_id: 1,
  purchase_price: 400000,
  listing_date: '2026-07-05',
  sales_note: null,
  reserved_at: '2026-07-20T10:00:00+08:00',
  sold_at: '2026-07-29T10:00:00+08:00',
  sold_price: 510000,
  buyer_name: '買方',
  buyer_phone: '0987654321',
  buyer_customer_id: 2,
  notes: '紙本備註',
  created_at: '2026-07-01T10:00:00+08:00',
  updated_at: '2026-07-29T10:00:00+08:00',
}

const customerDetail: CustomerDetailResponse = {
  customer: {
    id: 1,
    name: '王小明',
    phone: '0912345678',
    line_id: null,
    customer_type: 'both',
    source: '介紹',
    address: null,
    notes: null,
    created_at: '2026-07-01T10:00:00+08:00',
    updated_at: '2026-07-01T10:00:00+08:00',
  },
  vehicles_as_seller: [],
  vehicles_as_buyer: [],
}

function CustomerSelectHarness({ selected = false }: { selected?: boolean }) {
  const [value, setValue] = useState({
    customerId: selected ? '1' : '',
    name: selected ? '王小明' : '',
    phone: selected ? '0912345678' : '',
  })

  return (
    <CustomerSelect
      {...value}
      nameLabel="買方姓名"
      phoneLabel="買方電話"
      required
      onChange={setValue}
    />
  )
}

describe('Customer, audit and print presentation', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(useAuth).mockReturnValue({ user: admin } as ReturnType<typeof useAuth>)
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  it('keeps visible customer labels, selected-data reason and removes the redundant phone placeholder', () => {
    render(<CustomerSelectHarness selected />)

    expect(screen.getByLabelText('買方姓名 *')).toBeTruthy()
    const phone = screen.getByLabelText('買方電話')
    const selectedHint = screen.getByText('已選擇既有客戶，電話由客戶資料帶入')
    expect(phone.getAttribute('readonly')).not.toBeNull()
    expect(phone.getAttribute('placeholder')).toBeNull()
    expect(phone.getAttribute('aria-describedby')).toBe(selectedHint.id)
  })

  it('keeps customer search loading and no-result next step concise for sighted and screen-reader users', async () => {
    vi.useFakeTimers()
    vi.mocked(customersApi.listCustomers).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
    })
    render(<CustomerSelectHarness />)

    const name = screen.getByLabelText('買方姓名 *')
    fireEvent.focus(name)
    fireEvent.change(name, { target: { value: '不存在' } })
    expect(screen.getByText('搜尋中...')).toBeTruthy()

    await act(async () => {
      vi.advanceTimersByTime(250)
      await Promise.resolve()
      await Promise.resolve()
    })

    expect(screen.getAllByText('查無相符客戶；填寫電話後將建立新客戶')).toHaveLength(2)
    const phone = screen.getByLabelText('買方電話 *')
    expect(phone.getAttribute('placeholder')).toBeNull()
    expect(phone.getAttribute('aria-describedby')).toBeNull()
  })

  it('distinguishes filtered customer no-result and keeps the clear action', async () => {
    vi.mocked(customersApi.listCustomers).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
    })
    render(
      <MemoryRouter>
        <CustomerList />
      </MemoryRouter>,
    )

    fireEvent.change(screen.getByPlaceholderText('搜尋姓名 / 電話 / Line ID'), {
      target: { value: '王小明' },
    })

    expect(await screen.findByText('尚無符合條件的客戶')).toBeTruthy()
    expect(screen.getByRole('button', { name: '清除篩選條件' })).toBeTruthy()
    expect(screen.queryByText('尚無客戶資料')).toBeNull()
  })

  it('keeps customer relation empty states and delete errors', async () => {
    const interaction = userEvent.setup()
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    vi.mocked(customersApi.getCustomer).mockResolvedValue(customerDetail)
    vi.mocked(customersApi.deleteCustomer).mockRejectedValue(new Error('blocked'))

    render(
      <MemoryRouter initialEntries={['/customers/1']}>
        <Routes>
          <Route path="/customers/:id" element={<CustomerDetail />} />
        </Routes>
      </MemoryRouter>,
    )

    expect(await screen.findAllByText('尚無相關車輛')).toHaveLength(2)
    await interaction.click(screen.getByRole('button', { name: '刪除' }))
    expect(confirm).toHaveBeenCalledWith('確定要刪除此客戶嗎？')
    const alert = await screen.findByRole('alert')
    expect(alert.textContent).toBe('刪除客戶失敗')
    expect(document.activeElement).toBe(alert)
    expect(screen.getByRole('heading', { name: '王小明' })).toBeTruthy()
    expect(screen.getByRole('link', { name: '返回列表' })).toBeTruthy()
  })

  it('keeps the audit read-only boundary, no-change state and request information', async () => {
    const interaction = userEvent.setup()
    const log: AuditLog = {
      id: 1,
      actor_id: 1,
      actor_name: '管理員',
      actor_email: 'admin@example.com',
      actor_role: 'admin',
      action: 'login',
      subject_type: 'authentication',
      subject_id: 1,
      subject_label: '管理員登入',
      before_values: null,
      after_values: null,
      ip_address: '127.0.0.1',
      user_agent: 'Test Browser',
      request_method: 'POST',
      request_path: 'api/login',
      created_at: '2026-07-29T10:00:00+08:00',
    }
    vi.mocked(auditLogsApi.listAuditLogs).mockResolvedValue({
      data: [log],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
    })

    render(<AuditLogList />)

    expect(await screen.findByText('紀錄僅供查詢。')).toBeTruthy()
    expect(screen.queryByText(/追蹤系統登入/)).toBeNull()
    await interaction.click(screen.getByRole('button', { name: '展開異動內容' }))
    expect(screen.getByText('此操作沒有可顯示的欄位異動。')).toBeTruthy()
    expect(screen.getByText('POST /api/login')).toBeTruthy()
    expect(screen.getByText('Test Browser')).toBeTruthy()
  })

  it('keeps all closing-print money fields, descriptions and approved summary values', async () => {
    const response: VehiclePrintClosingResponse = {
      printed_at: '2026-07-29T12:00:00+08:00',
      vehicle,
      summary: { income_total: 510000, expense_total: 400000, gross_profit: 110000 },
      money_entries: [
        {
          id: 1,
          entry_date: '2026-07-20',
          direction: 'income',
          category: '訂金收入',
          amount: 10000,
          approval_status: 'approved',
          counterparty_name: '買方',
          description: '成交訂金',
          cash_account: null,
        },
      ],
    }
    vi.mocked(vehiclesApi.getVehiclePrintClosing).mockResolvedValue(response)

    render(
      <MemoryRouter initialEntries={['/vehicles/7/print/closing']}>
        <Routes>
          <Route path="/vehicles/:id/print/closing" element={<VehicleClosingPrint />} />
        </Routes>
      </MemoryRouter>,
    )

    expect(await screen.findByRole('heading', { name: '成交結案收支明細' })).toBeTruthy()
    expect(screen.getAllByRole('columnheader', { name: '說明' })).toHaveLength(2)
    const incomeRow = screen.getByText('成交訂金').closest('tr')
    expect(incomeRow).not.toBeNull()
    expect(within(incomeRow!).getByText('$10,000')).toBeTruthy()
    expect(screen.getByText('收入合計：$510,000')).toBeTruthy()
    expect(screen.getByText('支出合計：$400,000')).toBeTruthy()
    expect(screen.getByText('單車毛利：$110,000')).toBeTruthy()
    expect(screen.getByText('紙本備註')).toBeTruthy()
  })

  it('keeps intake-print formal labels and sign-off fields', async () => {
    const response: VehiclePrintIntakeResponse = {
      printed_at: '2026-07-29T12:00:00+08:00',
      vehicle,
    }
    vi.mocked(vehiclesApi.getVehiclePrintIntake).mockResolvedValue(response)

    render(
      <MemoryRouter initialEntries={['/vehicles/7/print/intake']}>
        <Routes>
          <Route path="/vehicles/:id/print/intake" element={<VehicleIntakePrint />} />
        </Routes>
      </MemoryRouter>,
    )

    expect(await screen.findByRole('heading', { name: '車輛建檔資料' })).toBeTruthy()
    expect(screen.getByText('貸款 / 權利問題備註')).toBeTruthy()
    expect(screen.getByText('收購價')).toBeTruthy()
    expect(screen.getByText('$400,000')).toBeTruthy()
    expect(screen.getByText('經辦人簽名')).toBeTruthy()
    expect(screen.getByText('主管簽名')).toBeTruthy()
  })
})
