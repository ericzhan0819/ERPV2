import { CircleCheck, CircleSlash } from 'lucide-react'

export function ActiveStatusBadge({ active }: { active: boolean }) {
  return (
    <span className={`badge ${active ? 'badge-emerald' : 'badge-slate'}`}>
      {active ? <CircleCheck size={12} /> : <CircleSlash size={12} />}
      {active ? '啟用中' : '已停用'}
    </span>
  )
}
