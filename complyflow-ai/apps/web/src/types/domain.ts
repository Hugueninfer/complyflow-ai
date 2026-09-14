export type FindingStatus = 'met' | 'partial' | 'missing' | 'inconclusive'
export type Role = 'owner' | 'analyst' | 'reviewer'
export interface DemoSession {
  id: string
  organization_id: string
  expires_at: string
  quotas: { suppliers: number; analyses: number; storage_bytes: number }
}
export interface Session {
  user: { id: string; name: string; email: string }
  organization: { id: string; name: string }
  role: Role
  permissions: string[]
  demo: DemoSession | null
}
export interface Envelope<T> { data: T }
