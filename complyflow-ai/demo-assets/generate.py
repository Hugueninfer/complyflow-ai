"""Deterministic demo documents. Run with reportlab and pypdf installed."""
from hashlib import sha256
import json
from pathlib import Path

from pypdf import PdfReader
from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import mm
from reportlab.pdfgen.canvas import Canvas
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle

ROOT = Path(__file__).resolve().parents[1]
WARNING = "DOCUMENTO FICTÍCIO - SOMENTE DEMONSTRAÇÃO"
NAVY = colors.HexColor('#12243a')
TEAL = colors.HexColor('#087f8c')
DATA = [
    ('certidao-ficticia.pdf', '01 / REGULARIDADE', 'Declaração documental',
     'Certidão fictícia para inspeção de evidências',
     [('Referência interna', 'DEMO-DOC-01'), ('Período ilustrativo', '01/01/2026 a 31/12/2026'), ('Escopo', 'Cadastro documental simulado')],
     [('Objeto', 'A documentação cadastral simulada está completa no período ilustrativo de 2026.'),
      ('Critério de leitura', 'Esta declaração permite exercitar a localização de evidências e a revisão de um requisito documental. Não representa regularidade fiscal, trabalhista ou jurídica.'),
      ('Limitações', 'Nenhum órgão público emitiu este documento. Não foram realizadas consultas a bases oficiais. Não há assinatura, registro fiscal, selo ou número de certificação válido.')]),
    ('politica-privacidade-ficticia.pdf', '02 / PRIVACIDADE', 'Política de privacidade',
     'Diretrizes ilustrativas de tratamento de informações',
     [('Referência interna', 'DEMO-DOC-02'), ('Versão ilustrativa', '1.0 / setembro de 2026'), ('Escopo', 'Fluxo documental de demonstração')],
     [('Finalidade e acesso', 'O tratamento de dados pessoais limita-se à finalidade informada e o acesso é restrito às funções autorizadas.'),
      ('Retenção', 'A política prevê revisão periódica dos registros. O prazo objetivo de retenção e o procedimento de eliminação ainda não foram definidos neste exemplo.'),
      ('Limitações', 'Este texto fictício é deliberadamente incompleto para permitir um achado parcial e uma revisão humana. Não constitui política de uma empresa real nem atesta conformidade com legislação.')]),
    ('balanco-ficticio.pdf', '03 / FINANÇAS', 'Resumo financeiro',
     'Balanço fictício com números ilustrativos',
     [('Referência interna', 'DEMO-DOC-03'), ('Data-base ilustrativa', '31/12/2025'), ('Unidade', 'Valores fictícios em R$')],
     [('Posição ilustrativa', 'Ativos: R$ 120.000. Passivos: R$ 80.000. Patrimônio líquido: R$ 40.000.'),
      ('Base de avaliação', 'Os valores ilustrativos não foram auditados e não incluem notas explicativas nem projeções de liquidez. A capacidade financeira permanece inconclusiva.'),
      ('Limitações', 'Não há contador, assinatura ou entidade real associada a estes números. O documento não serve para contratação, crédito, prestação de contas ou comprovação patrimonial.')]),
]


class DeterministicCanvas(Canvas):
    def __init__(self, *args, **kwargs):
        kwargs['invariant'] = 1
        super().__init__(*args, **kwargs)


def chrome(canvas, doc):
    width, height = A4
    canvas.saveState()
    canvas.setFillColor(NAVY)
    canvas.rect(0, height - 27*mm, width, 27*mm, fill=1, stroke=0)
    canvas.setFillColor(colors.white)
    canvas.setFont('Helvetica-Bold', 9)
    canvas.drawCentredString(width/2, height - 16*mm, WARNING)
    canvas.setStrokeColor(TEAL)
    canvas.line(22*mm, 27*mm, width-22*mm, 27*mm)
    canvas.setFillColor(NAVY)
    canvas.setFont('Helvetica', 8)
    canvas.drawString(22*mm, 20*mm, 'Material de demonstração / Sem validade comprobatória')
    canvas.drawRightString(width-22*mm, 20*mm, f'{doc.page} / 1')
    canvas.restoreState()


def main():
    output = ROOT / 'output/pdf'
    output.mkdir(parents=True, exist_ok=True)
    styles = {
        'eyebrow': ParagraphStyle('eyebrow', fontName='Helvetica-Bold', fontSize=9, textColor=TEAL, spaceAfter=12),
        'title': ParagraphStyle('title', fontName='Helvetica-Bold', fontSize=27, leading=31, textColor=NAVY, spaceAfter=10),
        'subtitle': ParagraphStyle('subtitle', fontName='Helvetica', fontSize=11, leading=16, textColor=NAVY, spaceAfter=24),
        'heading': ParagraphStyle('heading', fontName='Helvetica-Bold', fontSize=12, leading=16, textColor=TEAL, spaceBefore=20, spaceAfter=7),
        'body': ParagraphStyle('body', fontName='Helvetica', fontSize=11, leading=17, textColor=NAVY),
        'notice': ParagraphStyle('notice', fontName='Helvetica-Bold', fontSize=9, leading=14, textColor=NAVY, alignment=TA_CENTER),
    }
    manifest = []
    for filename, eyebrow, title, subtitle, facts, sections in DATA:
        path = output / filename
        doc = SimpleDocTemplate(str(path), pagesize=A4, rightMargin=22*mm, leftMargin=22*mm, topMargin=39*mm, bottomMargin=35*mm, title=title+' - fictício', author='Demonstração fictícia', pageCompression=1)
        story = [Paragraph(eyebrow, styles['eyebrow']), Paragraph(title, styles['title']), Paragraph(subtitle, styles['subtitle'])]
        table = Table([[Paragraph(k, styles['body']), Paragraph(v, styles['body'])] for k, v in facts], colWidths=[57*mm, 109*mm])
        table.setStyle(TableStyle([('BACKGROUND', (0,0), (-1,-1), colors.HexColor('#f0f5f7')), ('VALIGN', (0,0), (-1,-1), 'TOP'), ('TOPPADDING',(0,0),(-1,-1),9), ('BOTTOMPADDING',(0,0),(-1,-1),9), ('LEFTPADDING',(0,0),(-1,-1),12)]))
        story.append(table)
        for heading, body in sections:
            story.extend([Paragraph(heading, styles['heading']), Paragraph(body, styles['body'])])
        story.extend([Spacer(1, 24), Paragraph('Todo o conteúdo é inventado para demonstração.<br/>Não usar em processos reais.', styles['notice'])])
        doc.build(story, onFirstPage=chrome, onLaterPages=chrome, canvasmaker=DeterministicCanvas)
        pages = [' '.join(page.extract_text().split()) for page in PdfReader(path).pages]
        assert len(pages) == 1
        assert all(WARNING in page for page in pages)
        alias = ROOT / 'demo-assets' / filename
        if not alias.is_symlink():
            alias.symlink_to('../output/pdf/'+filename)
        manifest.append({'filename': filename, 'sha256': sha256(path.read_bytes()).hexdigest(), 'size_bytes': path.stat().st_size, 'pages': pages, 'excerpt': sections[0][1] if filename != 'balanco-ficticio.pdf' else sections[1][1]})
    (ROOT / 'demo-assets/manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2)+'\n')
    print(json.dumps([{k: v for k, v in item.items() if k != 'pages'} for item in manifest], ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
