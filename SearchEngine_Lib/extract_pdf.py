import fitz  # PyMuPDF
import sys
import json

def extract_pdf_with_pages(pdf_path, max_pages=50):
    try:
        doc = fitz.open(pdf_path)
        result = {
            'total_pages': len(doc),
            'pages': []
        }
        
        for page_num in range(min(len(doc), max_pages)):
            page = doc[page_num]
            
            # Extract text with layout preservation
            text = page.get_text("text")
            
            # Extract tables if present
            tables = page.find_tables()
            table_data = []
            for table in tables:
                table_data.append(table.extract())
            
            result['pages'].append({
                'page_number': page_num + 1,
                'text': text,
                'tables': table_data
            })
        
        doc.close()
        return result
        
    except Exception as e:
        return {'error': str(e)}

if __name__ == "__main__":
    pdf_path = sys.argv[1]
    max_pages = int(sys.argv[2]) if len(sys.argv) > 2 else 50
    
    result = extract_pdf_with_pages(pdf_path, max_pages)
    print(json.dumps(result, ensure_ascii=False, indent=2))