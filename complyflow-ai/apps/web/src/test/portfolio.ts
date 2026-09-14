import { finding, humanReview } from './analysis'
import { supplier, requirementSet } from './workspace'
export const portfolioPermissions = ['supplier.view', 'requirement.view', 'analysis.view', 'document.view', 'audit.view', 'supplier.decide', 'finding.review']
export const secondSupplier = { ...supplier, id: 'supplier-2', name: 'Atlas Serviços' }
export const published = { ...requirementSet, status: 'published' }
export const comparison = {
  requirement_set: { id: 'set-1', name: 'Homologação', version: 1, is_current: false },
  left: { supplier, analysis: { id: 'run-1', completed_at: '2026-09-13T12:00:00Z', is_latest_for_supplier: false } },
  right: { supplier: secondSupplier, analysis: null },
  rows: [{ requirement: finding.requirement, left: { finding_id: finding.id, ai: { status: finding.status, justification: finding.justification, confidence: finding.confidence, search_summary: null }, human_review: humanReview, requires_human_review: false, evidence: finding.citations.map(c => ({ ...c, quote_truncated: false })), evidence_total: 1 }, right: null }],
}
export const decisionContext = { analysis_id: 'run-1', supplier_id: supplier.id, requirement_set_id: 'set-1', status: 'completed', required_pending: 0, is_latest_for_supplier: true, is_current_checklist: true, decision: null }
export const decision = { id: 'decision-1', supplier_id: supplier.id, analysis_id: 'run-1', requirement_set_id: 'set-1', decision: 'conditional', reason: 'Escopo limitado por revisão humana.', decided_at: '2026-09-13T15:00:00Z' }
export const auditEvent = { id: 'event-1', organization_public_id: 'org-1', actor_public_id: 'user-1', action: 'supplier.decided', target_type: 'supplier_decision', target_id: 'decision-1', metadata: { analysis_id: 'run-1', supplier_id: supplier.id, decision: 'conditional' }, previous_hash: 'b'.repeat(64), event_hash: 'a'.repeat(64), occurred_at: '2026-09-13T15:00:00Z' }
