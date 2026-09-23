import type { MoneyDirection } from '../types/moneyEntry'

export const incomeCategories = ['訂金收入', '尾款收入', '其他單車收入', '一般收入']

export const expenseCategories = [
  '購車付款',
  '維修支出',
  '美容支出',
  '代辦支出',
  '拍場支出',
  '退款',
  '租金',
  '水電',
  '廣告',
  '平台費',
  '薪資 / 佣金',
  '稅金支出',
  '其他支出',
]

export const allCategories = [...incomeCategories, ...expenseCategories]

export const directionLabels: Record<MoneyDirection, string> = {
  income: '收入',
  expense: '支出',
}

export function categoriesForDirection(direction: MoneyDirection | ''): string[] {
  if (direction === 'income') return incomeCategories
  if (direction === 'expense') return expenseCategories
  return allCategories
}
