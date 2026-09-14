export type FindingStatus = 'met' | 'partial' | 'missing' | 'inconclusive'
export type Role = 'owner' | 'analyst' | 'reviewer'
export interface AnalysisRun { id: string; status: 'pending' | 'processing' | 'completed' | 'failed'; attempts: number; progress: number; error_code: string | null; error_message: string | null; created_at: string; started_at: string | null; completed_at: string | null }
export interface FindingReview { id: string; finding_id: string; status: FindingStatus; justification: string; note: string | null; reviewed_at: string }
export interface Finding { id: string; requirement: { id: string; code: string; title: string; category: string; weight: number; position: number; is_required: boolean }; status: FindingStatus; justification: string; confidence: number; search_summary: string | null; requires_human_review: boolean; latest_review: FindingReview | null; review_locked: boolean; citations: { id: string; document_id: string; page_number: number; quote: string; start_offset: number; end_offset: number }[] }
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
export interface Supplier { id: string; name: string; tax_id: string | null; risk_level: 'low' | 'medium' | 'high'; latest_analysis?: Pick<AnalysisRun, 'id' | 'status'> | null }
export interface DocumentMetadata { id: string; storage_name: string; mime_type: string; size_bytes: number; sha256: string; status: string }
export interface DocumentPage extends Envelope<DocumentMetadata[]> { meta: { current_page: number; last_page: number; total: number } }
export interface Requirement { id?: string; code: string; title: string; category: string; weight: number | string; position: number; evaluation_text: string; is_required: boolean }
export interface RequirementSet { id: string; name: string; version: number; status: 'draft' | 'published'; published_at: string | null; requirements: Requirement[] }
