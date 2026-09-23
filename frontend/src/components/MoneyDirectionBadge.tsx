import { ArrowDownRight, ArrowUpRight } from 'lucide-react'
import type { MoneyDirection } from '../types/moneyEntry'
import { directionLabels } from '../utils/moneyEntryCategory'

export function MoneyDirectionBadge({ direction }: { direction: MoneyDirection }) {
  const isIncome = direction === 'income'
  return (
    <span className={`badge ${isIncome ? 'badge-emerald' : 'badge-red'}`}>
      {isIncome ? <ArrowUpRight size={12} /> : <ArrowDownRight size={12} />}
      {directionLabels[direction]}
    </span>
  )
}
