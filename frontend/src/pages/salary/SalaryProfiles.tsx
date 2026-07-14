import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { listSalaryProfiles, updateSalaryProfile } from '../../api/salaryProfiles'
import { listUsers } from '../../api/users'
import type { SalaryProfile, SalaryProfilePayload } from '../../types/salary'
import type { User } from '../../types/user'
import { apiError, apiValidationErrors } from './salaryUtils'

const amountFields = [
  ['base_salary', '底薪'],
  ['fixed_allowance', '固定津貼'],
  ['labor_insurance_deduction', '勞保扣款'],
  ['health_insurance_deduction', '健保扣款'],
] as const

export function SalaryProfiles() {
  const [profiles, setProfiles] = useState<SalaryProfile[]>([])
  const [users, setUsers] = useState<User[]>([])
  const [editing, setEditing] = useState<number | null>(null)
  const [form, setForm] = useState<SalaryProfilePayload | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)

  function load() {
    Promise.all([listSalaryProfiles(), listUsers()])
      .then(([loadedProfiles, loadedUsers]) => {
        setProfiles(loadedProfiles)
        setUsers(loadedUsers)
      })
      .catch((caught) => setError(apiError(caught, '薪資設定載入失敗')))
  }

  useEffect(() => { load() }, [])

  function startEditing(profile: SalaryProfile) {
    setEditing(profile.user_id)
    setFieldErrors({})
    setForm({
      base_salary: profile.base_salary,
      fixed_allowance: profile.fixed_allowance,
      labor_insurance_deduction: profile.labor_insurance_deduction,
      health_insurance_deduction: profile.health_insurance_deduction,
      commission_enabled: profile.commission_enabled,
      is_active: profile.is_active,
    })
  }

  async function save() {
    if (!editing || !form) return
    setSaving(true)
    setError(null)
    setFieldErrors({})
    try {
      await updateSalaryProfile(editing, form)
      setEditing(null)
      load()
    } catch (caught) {
      setFieldErrors(apiValidationErrors(caught))
      setError(apiError(caught, '儲存薪資設定失敗'))
    } finally {
      setSaving(false)
    }
  }

  const rows = users.map((user) => (
    profiles.find((profile) => profile.user_id === user.id) ?? emptyProfile(user)
  ))

  return (
    <div className="flex flex-col gap-6">
      <div className="flex justify-between">
        <div>
          <h1 className="text-xl font-semibold text-fg">員工薪資設定</h1>
          <p className="mt-1 text-sm text-fg-muted">變更只影響未確認月份，不會回改歷史薪資快照。</p>
        </div>
        <Link to="/salary" className="text-sm text-primary">返回薪資月份</Link>
      </div>

      {error && <p className="text-sm text-error">{error}</p>}
      <div className="grid gap-4">
        {rows.map((profile) => (
          <SalaryProfileCard
            key={profile.user_id}
            profile={profile}
            editing={editing === profile.user_id}
            form={editing === profile.user_id ? form : null}
            fieldErrors={fieldErrors}
            saving={saving}
            onEdit={() => startEditing(profile)}
            onFormChange={setForm}
            onSave={save}
            onCancel={() => setEditing(null)}
          />
        ))}
      </div>
    </div>
  )
}

function SalaryProfileCard({
  profile,
  editing,
  form,
  fieldErrors,
  saving,
  onEdit,
  onFormChange,
  onSave,
  onCancel,
}: {
  profile: SalaryProfile
  editing: boolean
  form: SalaryProfilePayload | null
  fieldErrors: Record<string, string>
  saving: boolean
  onEdit: () => void
  onFormChange: (form: SalaryProfilePayload) => void
  onSave: () => void
  onCancel: () => void
}) {
  return (
    <section className="rounded-2xl border border-border bg-surface p-5 shadow-sm">
      <div className="flex justify-between">
        <div>
          <h2 className="font-semibold text-fg">{profile.user.name}</h2>
          <p className="text-xs text-fg-muted">
            {profile.user.email}{profile.id < 0 ? '・尚未設定薪資' : ''}
          </p>
        </div>
        {!editing && (
          <button onClick={onEdit} className="rounded-lg border border-border-strong px-3 py-1.5 text-sm">
            {profile.id < 0 ? '建立設定' : '編輯'}
          </button>
        )}
      </div>

      {editing && form ? (
        <SalaryProfileForm
          form={form}
          fieldErrors={fieldErrors}
          saving={saving}
          onChange={onFormChange}
          onSave={onSave}
          onCancel={onCancel}
        />
      ) : (
        <SalaryProfileSummary profile={profile} />
      )}
    </section>
  )
}

function SalaryProfileForm({
  form,
  fieldErrors,
  saving,
  onChange,
  onSave,
  onCancel,
}: {
  form: SalaryProfilePayload
  fieldErrors: Record<string, string>
  saving: boolean
  onChange: (form: SalaryProfilePayload) => void
  onSave: () => void
  onCancel: () => void
}) {
  return (
    <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      {amountFields.map(([field, label]) => (
        <label key={field} className="text-sm text-fg-muted">
          {label} <span className="text-error">*</span>
          <input
            type="number"
            min="0"
            required
            value={form[field]}
            onChange={(event) => onChange({ ...form, [field]: Number(event.target.value) })}
            className="mt-1 w-full rounded-lg border border-border-strong bg-surface px-3 py-2 text-fg"
          />
          {fieldErrors[field] && <span className="mt-1 block text-xs text-error">{fieldErrors[field]}</span>}
        </label>
      ))}
      <label className="flex items-center gap-2 text-sm">
        <input
          type="checkbox"
          checked={form.commission_enabled}
          onChange={(event) => onChange({ ...form, commission_enabled: event.target.checked })}
        />
        啟用獎金
      </label>
      <label className="flex items-center gap-2 text-sm">
        <input
          type="checkbox"
          checked={form.is_active}
          onChange={(event) => onChange({ ...form, is_active: event.target.checked })}
        />
        納入薪資結算
      </label>
      <div className="flex gap-2 sm:col-span-2 lg:col-span-4">
        <button disabled={saving} onClick={onSave} className="rounded-lg bg-primary px-4 py-2 text-sm text-primary-fg">
          {saving ? '儲存中...' : '儲存'}
        </button>
        <button onClick={onCancel} className="rounded-lg border border-border-strong px-4 py-2 text-sm">取消</button>
      </div>
    </div>
  )
}

function SalaryProfileSummary({ profile }: { profile: SalaryProfile }) {
  return (
    <div className="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3 lg:grid-cols-6">
      <ProfileMetric label="底薪" value={profile.base_salary.toLocaleString()} />
      <ProfileMetric label="固定津貼" value={profile.fixed_allowance.toLocaleString()} />
      <ProfileMetric label="勞保" value={profile.labor_insurance_deduction.toLocaleString()} />
      <ProfileMetric label="健保" value={profile.health_insurance_deduction.toLocaleString()} />
      <ProfileMetric label="獎金" value={profile.commission_enabled ? '啟用' : '停用'} />
      <ProfileMetric label="結算" value={profile.is_active ? '納入' : '停用'} />
    </div>
  )
}

function ProfileMetric({ label, value }: { label: string; value: string }) {
  return <span>{label}<br /><b>{value}</b></span>
}

function emptyProfile(user: User): SalaryProfile {
  return {
    id: -user.id,
    user_id: user.id,
    user: {
      id: user.id,
      name: user.name,
      email: user.email,
      role: user.role,
      is_active: user.is_active,
    },
    base_salary: 0,
    fixed_allowance: 0,
    labor_insurance_deduction: 0,
    health_insurance_deduction: 0,
    commission_enabled: false,
    is_active: false,
  }
}
