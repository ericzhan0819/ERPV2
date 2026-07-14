import type { SalaryPeriodStatus } from '../../types/salary'

export function SalaryStatusBadge({ status }: { status: SalaryPeriodStatus }) {
  const config = { draft: ['草稿', 'badge-amber'], confirmed: ['已確認', 'badge-blue'], paid: ['已發薪', 'badge-emerald'] }[status]
  return <span className={`badge ${config[1]}`}>{config[0]}</span>
}
