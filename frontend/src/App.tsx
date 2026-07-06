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
import { VehicleIntakePrint } from './pages/print/VehicleIntakePrint'
import { VehicleClosingPrint } from './pages/print/VehicleClosingPrint'

function App() {
  return (
    <AuthProvider>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route
          path="/vehicles/:id/print/intake"
          element={
            <ProtectedRoute allowedRoles={['admin', 'manager']}>
              <VehicleIntakePrint />
            </ProtectedRoute>
          }
        />
        <Route
          path="/vehicles/:id/print/closing"
          element={
            <ProtectedRoute allowedRoles={['admin', 'manager']}>
              <VehicleClosingPrint />
            </ProtectedRoute>
          }
        />
        <Route
          element={
            <ProtectedRoute>
              <AppLayout />
            </ProtectedRoute>
          }
        >
          <Route path="/dashboard" element={<Dashboard />} />
          <Route path="/vehicles" element={<VehicleList />} />
          <Route
            path="/vehicles/create"
            element={
              <ProtectedRoute allowedRoles={['admin', 'manager']}>
                <VehicleCreate />
              </ProtectedRoute>
            }
          />
          <Route path="/vehicles/:id" element={<VehicleDetail />} />
          <Route
            path="/money-entries"
            element={
              <ProtectedRoute allowedRoles={['admin', 'manager', 'sales']}>
                <MoneyEntryList />
              </ProtectedRoute>
            }
          />
          <Route
            path="/money-entries/create"
            element={
              <ProtectedRoute allowedRoles={['admin', 'manager', 'sales']}>
                <MoneyEntryCreate />
              </ProtectedRoute>
            }
          />
          <Route
            path="/cash-accounts"
            element={
              <ProtectedRoute allowedRoles={['admin', 'manager']}>
                <CashAccountList />
              </ProtectedRoute>
            }
          />
          <Route
            path="/users"
            element={
              <ProtectedRoute allowedRoles={['admin']}>
                <UserList />
              </ProtectedRoute>
            }
          />
        </Route>
        <Route path="/" element={<Navigate to="/dashboard" replace />} />
      </Routes>
    </AuthProvider>
  )
}

export default App
