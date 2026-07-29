// @vitest-environment jsdom

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as cashAccountsApi from '../../api/cashAccounts'
import * as vehiclePhotosApi from '../../api/vehiclePhotos'
import * as vehiclesApi from '../../api/vehicles'
import { useAuth } from '../../hooks/useAuth'
import type { User, UserRole } from '../../types/user'
import type { Vehicle, VehicleDetailResponse, VehicleListItem, VehiclePhoto } from '../../types/vehicle'
import { VehicleCreate } from './VehicleCreate'
import { VehicleDetail } from './VehicleDetail'
import { VehicleList } from './VehicleList'

vi.mock('../../api/cashAccounts', () => ({
  listCashAccountOptions: vi.fn(),
}))

vi.mock('../../api/vehiclePhotos', () => ({
  deleteVehiclePhoto: vi.fn(),
  listVehiclePhotos: vi.fn(),
  reorderVehiclePhotos: vi.fn(),
  setCoverVehiclePhoto: vi.fn(),
  uploadVehiclePhotos: vi.fn(),
}))

vi.mock('../../api/vehicles', () => ({
  closeSaleVehicle: vi.fn(),
  createVehicle: vi.fn(),
  getVehicle: vi.fn(),
  listCommissionAgentOptions: vi.fn(),
  listVehicleForSale: vi.fn(),
  listVehicles: vi.fn(),
  recordFinalPayment: vi.fn(),
  recordVehicleExpense: vi.fn(),
  reserveVehicle: vi.fn(),
  updateVehiclePurchasePrice: vi.fn(),
}))

vi.mock('../../hooks/useAuth', () => ({
  useAuth: vi.fn(),
}))

function userWithRole(role: UserRole): User {
  return {
    id: 1,
    name: '測試使用者',
    email: 'user@example.com',
    username: 'user',
    must_change_password: false,
    role,
    is_admin: role === 'admin',
    is_active: true,
    phone: null,
    job_title: null,
    hire_date: null,
    notes: null,
  }
}

function setRole(role: UserRole) {
  vi.mocked(useAuth).mockReturnValue({
    user: userWithRole(role),
  } as ReturnType<typeof useAuth>)
}

const vehicle: Vehicle = {
  id: 7,
  stock_no: 'V2026070001',
  status: 'reserved',
  brand: 'Toyota',
  model: 'Corolla',
  year: 2022,
  license_plate: 'ABC-1234',
  vin: null,
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
  purchase_agent_id: 1,
  purchase_agent: { id: 1, name: '收車人', role: 'manager' },
  asking_price: 520000,
  floor_price: 500000,
  listing_date: '2026-07-05',
  sales_note: null,
  reserved_at: '2026-07-20T10:00:00+08:00',
  sold_at: null,
  sold_price: 510000,
  sales_agent_id: 2,
  sales_agent: { id: 2, name: '賣車人', role: 'sales' },
  buyer_name: '買方',
  buyer_phone: '0987654321',
  buyer_customer_id: 2,
  notes: null,
  created_at: '2026-07-01T10:00:00+08:00',
  updated_at: '2026-07-20T10:00:00+08:00',
}

const detail: VehicleDetailResponse = {
  vehicle,
  summary: {
    income_total: 10000,
    expense_total: 400000,
    gross_profit: -390000,
  },
  sales_collection_summary: {
    sold_price: 510000,
    approved_collection_total: 10000,
    pending_collection_total: 0,
    approved_refund_total: 0,
    pending_refund_total: 0,
    net_recorded_collection_total: 10000,
    remaining_amount: 500000,
  },
  money_entries: [],
  commission_attribution_lock: null,
}

const photos: VehiclePhoto[] = [
  {
    id: 1,
    vehicle_id: 7,
    url: '/photos/1.webp',
    thumbnail_url: '/photos/1-thumb.webp',
    original_filename: 'front.webp',
    mime_type: 'image/webp',
    size: 2048,
    width: 1200,
    height: 900,
    sort_order: 0,
    is_cover: true,
    uploaded_by: 1,
    created_at: '2026-07-01T10:00:00+08:00',
    updated_at: '2026-07-01T10:00:00+08:00',
  },
  {
    id: 2,
    vehicle_id: 7,
    url: '/photos/2.webp',
    thumbnail_url: '/photos/2-thumb.webp',
    original_filename: 'rear.webp',
    mime_type: 'image/webp',
    size: 4096,
    width: 1200,
    height: 900,
    sort_order: 1,
    is_cover: false,
    uploaded_by: 1,
    created_at: '2026-07-01T10:00:00+08:00',
    updated_at: '2026-07-01T10:00:00+08:00',
  },
]

function renderList(path = '/vehicles') {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/vehicles" element={<VehicleList />} />
      </Routes>
    </MemoryRouter>,
  )
}

function renderDetail() {
  render(
    <MemoryRouter initialEntries={['/vehicles/7']}>
      <Routes>
        <Route path="/vehicles/:id" element={<VehicleDetail />} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('Vehicle presentation', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setRole('admin')
    vi.mocked(cashAccountsApi.listCashAccountOptions).mockResolvedValue([
      { id: 1, name: '營運現金', type: 'cash', is_active: true },
    ])
    vi.mocked(vehiclesApi.listCommissionAgentOptions).mockResolvedValue([
      { id: 1, name: '測試使用者', role: 'admin' },
    ])
    vi.mocked(vehiclesApi.getVehicle).mockResolvedValue(detail)
    vi.mocked(vehiclePhotosApi.listVehiclePhotos).mockResolvedValue(photos)
  })

  it('keeps active filters, no-result copy and the clear action distinct from an empty inventory', async () => {
    vi.mocked(vehiclesApi.listVehicles).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
    })

    renderList('/vehicles?search=Corolla')

    expect(await screen.findByText('尚無符合條件的車輛')).toBeTruthy()
    expect(screen.getByLabelText('已套用篩選條件').textContent).toContain('搜尋：Corolla')
    expect(screen.getByRole('button', { name: '清除篩選條件' })).toBeTruthy()
    expect(screen.queryByText('目前沒有在庫車輛')).toBeNull()
  })

  it('keeps the unfiltered inventory empty state and its next action', async () => {
    vi.mocked(vehiclesApi.listVehicles).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
    })

    renderList()

    expect(await screen.findByText('目前沒有在庫車輛')).toBeTruthy()
    expect(screen.getByRole('link', { name: '新增第一輛車' }).getAttribute('href')).toBe('/vehicles/create')
    expect(screen.queryByText('尚無符合條件的車輛')).toBeNull()
  })

  it('keeps the out-of-range page state and route back to the first page', async () => {
    vi.mocked(vehiclesApi.listVehicles).mockResolvedValue({
      data: [],
      meta: { current_page: 3, last_page: 2, per_page: 20, total: 40 },
    })

    renderList('/vehicles?page=3')

    expect(await screen.findByText('此頁沒有資料')).toBeTruthy()
    expect(screen.getByRole('button', { name: '回到第 1 頁' })).toBeTruthy()
    expect(screen.getByText('至少保留一個車輛狀態。')).toBeTruthy()
  })

  it('keeps the no-photo state and sales role price masking on vehicle cards', async () => {
    setRole('sales')
    const listItem: VehicleListItem = { ...vehicle, status: 'listed', cover_photo: null }
    vi.mocked(vehiclesApi.listVehicles).mockResolvedValue({
      data: [listItem],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
    })

    renderList()

    const card = await screen.findByRole('link', { name: '查看 Toyota Corolla 詳情' })
    expect(within(card).getByText('尚無照片')).toBeTruthy()
    expect(within(card).getByText('開價')).toBeTruthy()
    expect(within(card).queryByText('底價')).toBeNull()
  })

  it('uses concise checkbox labels while preserving synchronous payment validation', async () => {
    render(
      <MemoryRouter>
        <VehicleCreate />
      </MemoryRouter>,
    )

    const synchronousPayment = screen.getByRole('checkbox', { name: '建立時同步記錄購車付款' })
    expect(screen.queryByText('是否建立時同步記錄購車付款')).toBeNull()
    expect(screen.getByRole('checkbox', { name: '有行照' })).toBeTruthy()
    expect(screen.getByRole('checkbox', { name: '已過戶' })).toBeTruthy()

    const licensePlateLabel = screen.getByText('車牌')
    const licensePlateInput = licensePlateLabel.nextElementSibling
    expect(licensePlateInput).toBeInstanceOf(HTMLInputElement)
    fireEvent.change(licensePlateInput!, { target: { value: 'ABC-1234' } })
    fireEvent.click(synchronousPayment)
    const submit = screen.getByRole('button', { name: '建立車輛' })
    fireEvent.submit(submit.closest('form')!)

    const alert = await screen.findByRole('alert')
    expect(alert.textContent).toBe('已勾選同步購車付款，請填寫付款金額與付款帳戶')
    expect(document.activeElement).toBe(alert)
    expect(vehiclesApi.createVehicle).not.toHaveBeenCalled()

    submit.focus()
    fireEvent.submit(submit.closest('form')!)
    expect(document.activeElement).toBe(alert)
  })

  it('keeps high-risk vehicle outcomes and description fields in workflow modals', async () => {
    const interaction = userEvent.setup()
    renderDetail()

    await screen.findByRole('heading', { name: '車輛照片' })
    await interaction.click(screen.getByRole('button', { name: '成交結案' }))
    const modalPanel = screen.getByRole('heading', { name: '成交結案' }).parentElement?.parentElement
    expect(modalPanel?.className).toContain('max-h-[calc(100dvh-2rem)]')
    expect(modalPanel?.className).toContain('overflow-y-auto')
    expect(
      screen.getByText('成交日期決定薪資獎金月份；已確認或已發薪月份不能新增成交。收款日期不影響成交月份。'),
    ).toBeTruthy()
    const soldDateLabel = screen.getByText('成交日期（預設今天）')
    expect(soldDateLabel.nextElementSibling).toHaveProperty('type', 'date')

    await interaction.click(screen.getByRole('button', { name: '關閉' }))
    await interaction.click(screen.getByRole('button', { name: '上報整備支出' }))
    expect(screen.getByText('送出後直接計入正式支出。')).toBeTruthy()
    const descriptionLabel = screen.getAllByText('說明').find((element) => element.tagName === 'LABEL')
    expect(descriptionLabel?.nextElementSibling?.tagName).toBe('TEXTAREA')
  })

  it('keeps the pending approval outcome for non-admin vehicle expenses', async () => {
    setRole('manager')
    const interaction = userEvent.setup()
    renderDetail()

    await screen.findByRole('heading', { name: '車輛照片' })
    await interaction.click(screen.getByRole('button', { name: '上報整備支出' }))

    expect(screen.getByText('送出後為待審核狀態，需老闆核准後才計入正式支出。')).toBeTruthy()
    expect(screen.queryByText('送出後直接計入正式支出。')).toBeNull()
  })

  it('keeps vehicle details visible when a successful action refresh fails', async () => {
    const interaction = userEvent.setup()
    vi.mocked(vehiclesApi.getVehicle)
      .mockResolvedValueOnce(detail)
      .mockRejectedValueOnce(new Error('offline'))
    vi.mocked(vehiclesApi.updateVehiclePurchasePrice).mockResolvedValue(undefined as never)
    renderDetail()

    await screen.findByRole('heading', { name: 'V2026070001' })
    await interaction.click(screen.getByRole('button', { name: '修正收購價' }))
    await interaction.click(screen.getByRole('button', { name: '儲存收購價' }))

    expect((await screen.findByRole('alert')).textContent).toBe(
      '操作已送出，但車輛資料可能不是最新；請重新整理後確認。',
    )
    expect(screen.getByRole('heading', { name: 'V2026070001' })).toBeTruthy()
    expect(screen.getByRole('link', { name: '列印建檔資料' })).toBeTruthy()
  })

  it('keeps loaded photos visible when an action refresh fails', async () => {
    const interaction = userEvent.setup()
    vi.mocked(vehiclePhotosApi.listVehiclePhotos)
      .mockResolvedValueOnce(photos)
      .mockRejectedValueOnce(new Error('offline'))
    vi.mocked(vehiclePhotosApi.setCoverVehiclePhoto).mockResolvedValue(photos[1])
    renderDetail()

    await screen.findByText('front.webp')
    await interaction.click(screen.getByRole('button', { name: '設封面' }))

    expect((await screen.findByRole('alert')).textContent).toBe('車輛照片載入失敗')
    expect(screen.getByText('front.webp')).toBeTruthy()
    expect(screen.getByText('rear.webp')).toBeTruthy()
    expect(screen.getByRole('button', { name: '設封面' })).toBeTruthy()
  })

  it('keeps purchase-price locking and photo cover, order, retry and irreversible deletion outcomes', async () => {
    const interaction = userEvent.setup()
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    vi.mocked(vehiclePhotosApi.uploadVehiclePhotos)
      .mockRejectedValueOnce(new Error('network'))
      .mockResolvedValueOnce(photos)
    vi.mocked(vehiclePhotosApi.deleteVehiclePhoto).mockRejectedValue(new Error('network'))
    vi.mocked(vehiclePhotosApi.reorderVehiclePhotos).mockResolvedValue(photos)
    vi.mocked(vehiclePhotosApi.setCoverVehiclePhoto).mockResolvedValue(photos[1])
    const { container } = render(
      <MemoryRouter initialEntries={['/vehicles/7']}>
        <Routes>
          <Route path="/vehicles/:id" element={<VehicleDetail />} />
        </Routes>
      </MemoryRouter>,
    )

    await screen.findByText('front.webp')
    expect(screen.getByText('封面')).toBeTruthy()
    expect(screen.getByRole('button', { name: '設封面' })).toBeTruthy()
    expect(screen.getAllByRole('button', { name: '←' })).toHaveLength(2)
    expect(screen.getAllByRole('button', { name: '→' })).toHaveLength(2)

    await interaction.click(screen.getAllByRole('button', { name: '→' })[0])
    await waitFor(() => {
      expect(vehiclePhotosApi.reorderVehiclePhotos).toHaveBeenCalledWith(7, [2, 1])
    })
    const setCover = screen.getByRole('button', { name: '設封面' })
    await waitFor(() => expect(setCover).toHaveProperty('disabled', false))
    await interaction.click(setCover)
    expect(vehiclePhotosApi.setCoverVehiclePhoto).toHaveBeenCalledWith(7, 2)

    const file = new File(['photo'], 'new.webp', { type: 'image/webp' })
    const fileInput = container.querySelector<HTMLInputElement>('input[type="file"]')
    expect(fileInput).not.toBeNull()
    fireEvent.change(fileInput!, { target: { files: [file] } })
    const uploadAlert = await screen.findByRole('alert')
    expect(uploadAlert.textContent).toBe('上傳照片失敗，請稍後再試')
    await waitFor(() => expect(document.activeElement).toBe(uploadAlert))
    await interaction.click(screen.getByRole('button', { name: '重試上傳' }))
    expect(vehiclePhotosApi.uploadVehiclePhotos).toHaveBeenCalledTimes(2)
    expect(vi.mocked(vehiclePhotosApi.uploadVehiclePhotos).mock.calls[1][2]).toBe(
      vi.mocked(vehiclePhotosApi.uploadVehiclePhotos).mock.calls[0][2],
    )

    await interaction.click(screen.getAllByRole('button', { name: '刪除' })[0])
    expect(confirm).toHaveBeenCalledWith('確定要刪除這張照片嗎？此動作無法復原。')
    expect(vehiclePhotosApi.deleteVehiclePhoto).toHaveBeenCalledWith(7, 1)
    const photoActionAlert = await screen.findByRole('alert')
    expect(photoActionAlert.textContent).toBe('刪除照片失敗，請稍後再試')
    expect(document.activeElement).toBe(photoActionAlert)

    await interaction.click(screen.getByRole('button', { name: '修正收購價' }))
    expect(
      screen.getByText('收購價影響單車毛利與薪資獎金；薪資月份確認或發薪後不可修改。'),
    ).toBeTruthy()
  })
})
