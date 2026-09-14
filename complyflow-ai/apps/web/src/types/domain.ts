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
export interface Supplier { id: string; name: string; tax_id: string | null; risk_level: 'low' | 'medium' | 'high' }
export interface DocumentMetadata { id: string; storage_name: string; mime_type: string; size_bytes: number; sha256: string; status: string }
export interface DocumentPage extends Envelope<DocumentMetadata[]> { meta: { current_page: number; last_page: number; total: number } }
export interface Requirement { id?: string; code: string; title: string; category: string; weight: number | string; position: number; evaluation_text: string; is_required: boolean }
export interface RequirementSet { id: string; name: string; version: number; status: 'draft' | 'published'; published_at: string | null; requirements: Requirement[] }
