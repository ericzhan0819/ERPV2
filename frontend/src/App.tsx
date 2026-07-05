import { Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider } from './hooks/useAuth'
import { ProtectedRoute } from './routes/ProtectedRoute'
import { AppLayout } from './layouts/AppLayout'
import { Login } from './pages/Login'
import { Dashboard } from './pages/Dashboard'
import { VehicleList } from './pages/vehicles/VehicleList'
import { VehicleCreate } from './pages/vehicles/VehicleCreate'
import { VehicleDetail } from './pages/vehicles/VehicleDetail'
import { MoneyEntryList } from './pages/money-entries/MoneyEntryList'
import { MoneyEntryCreate } from './pages/money-entries/MoneyEntryCreate'
import { CashAccountList } from './pages/cash-accounts/CashAccountList'
import { UserList } from './pages/users/UserList'

function App() {
  return (
    <AuthProvider>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route
          element={
            <ProtectedRoute>
              <AppLayout />
            </ProtectedRoute>
          }
        >
          <Route path="/dashboard" element={<Dashboard />} />
          <Route path="/vehicles" element={<VehicleList />} />
          <Route path="/vehicles/create" element={<VehicleCreate />} />
          <Route path="/vehicles/:id" element={<VehicleDetail />} />
          <Route path="/money-entries" element={<MoneyEntryList />} />
          <Route path="/money-entries/create" element={<MoneyEntryCreate />} />
          <Route path="/cash-accounts" element={<CashAccountList />} />
          <Route path="/users" element={<UserList />} />
        </Route>
        <Route path="/" element={<Navigate to="/dashboard" replace />} />
      </Routes>
    </AuthProvider>
  )
}

export default App
