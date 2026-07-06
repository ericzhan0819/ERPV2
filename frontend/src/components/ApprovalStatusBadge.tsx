import { CheckCircle2, Clock, XCircle } from 'lucide-react'
import type { MoneyEntryApprovalStatus } from '../types/moneyEntry'

const labels: Record<MoneyEntryApprovalStatus, string> = {
  approved: '已核准',
  pending: '待審核',
  rejected: '已駁回',
}

const badgeClasses: Record<MoneyEntryApprovalStatus, string> = {
  approved: 'badge-emerald',
  pending: 'badge-amber',
  rejected: 'badge-red',
}

const icons: Record<MoneyEntryApprovalStatus, typeof CheckCircle2> = {
  approved: CheckCircle2,
  pending: Clock,
  rejected: XCircle,
}

export function ApprovalStatusBadge({ status }: { status: MoneyEntryApprovalStatus }) {
  const Icon = icons[status]
  return (
    <span className={`badge ${badgeClasses[status]}`}>
      <Icon size={12} />
      {labels[status]}
    </span>
  )
}
