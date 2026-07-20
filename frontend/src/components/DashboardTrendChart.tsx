interface TrendPoint {
  date: string
  value: number
}

interface DashboardTrendChartProps {
  title: string
  description: string
  unit: string
  points: TrendPoint[]
  formatValue: (value: number) => string
}

const WIDTH = 640
const HEIGHT = 208
const PADDING_X = 20
const PADDING_TOP = 20
const PADDING_BOTTOM = 32

function formatDate(date: string): string {
  const [, month, day] = date.split('-')
  return `${month}/${day}`
}

export function DashboardTrendChart({
  title,
  description,
  unit,
  points,
  formatValue,
}: DashboardTrendChartProps) {
  const values = points.map((point) => point.value)
  const minimum = Math.min(...values)
  const maximum = Math.max(...values)
  const range = maximum - minimum || 1
  const chartWidth = WIDTH - PADDING_X * 2
  const chartHeight = HEIGHT - PADDING_TOP - PADDING_BOTTOM
  const coordinates = points.map((point, index) => {
    const x = PADDING_X + (index / Math.max(points.length - 1, 1)) * chartWidth
    const y = PADDING_TOP + ((maximum - point.value) / range) * chartHeight
    return { ...point, x, y }
  })
  const path = coordinates.map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x} ${point.y}`).join(' ')
  const labelIndexes = [...new Set([0, 7, 14, 21, points.length - 1])].filter(
    (index) => index >= 0 && index < points.length,
  )
  const titleId = `trend-${title.replace(/\s+/g, '-')}`

  return (
    <figure className="min-w-0 rounded-xl border border-border bg-surface p-4 shadow-sm sm:p-5">
      <figcaption>
        <h3 id={`${titleId}-title`} className="text-base font-semibold text-fg">{title}</h3>
        <p id={`${titleId}-description`} className="mt-1 text-xs text-fg-muted">{description}・單位：{unit}</p>
      </figcaption>

      <svg
        viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
        role="img"
        aria-labelledby={`${titleId}-title ${titleId}-description`}
        className="mt-4 block h-auto w-full overflow-visible text-primary"
      >
        {[0, 0.5, 1].map((ratio) => {
          const y = PADDING_TOP + ratio * chartHeight
          return (
            <line
              key={ratio}
              x1={PADDING_X}
              x2={WIDTH - PADDING_X}
              y1={y}
              y2={y}
              className="stroke-border"
              strokeWidth="1"
              strokeDasharray="4 6"
            />
          )
        })}
        <path d={path} fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />
        {coordinates.map((point, index) => (
          <circle
            key={point.date}
            cx={point.x}
            cy={point.y}
            r={index === 0 || index === points.length - 1 ? 4 : 2}
            fill="currentColor"
          />
        ))}
        {labelIndexes.map((index) => {
          const point = coordinates[index]
          return (
            <text
              key={point.date}
              x={point.x}
              y={HEIGHT - 8}
              textAnchor={index === 0 ? 'start' : index === points.length - 1 ? 'end' : 'middle'}
              className="fill-fg-muted text-[11px]"
            >
              {formatDate(point.date)}
            </text>
          )
        })}
      </svg>

      <div className="mt-2 flex flex-wrap justify-between gap-x-4 gap-y-1 text-xs text-fg-muted">
        <span>最低 {formatValue(minimum)}</span>
        <span>最高 {formatValue(maximum)}</span>
        <span>最新 {formatValue(points.at(-1)?.value ?? 0)}</span>
      </div>
      <ol className="sr-only">
        {points.map((point) => <li key={point.date}>{point.date}：{formatValue(point.value)}</li>)}
      </ol>
    </figure>
  )
}
