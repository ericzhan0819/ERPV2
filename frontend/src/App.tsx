import { Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider } from './hooks/useAuth'
import { ProtectedRoute } from './routes/ProtectedRoute'
import { AppLayout } from './layouts/AppLayout'
import { Login } from './pages/Login'
import { Dashboard } from './pages/Dashboard'
import { VehicleList } from './pages/vehicles/VehicleList'
import { VehicleCreate } from './pages/vehicles/VehicleCreate'
import { VehicleDetail } from './pages/vehicles/VehicleDetail'

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
        </Route>
        <Route path="/" element={<Navigate to="/dashboard" replace />} />
      </Routes>
    </AuthProvider>
  )
}

export default App
