<?php
// Cargar configuraciones desde archivos externos
$config = json_decode(file_get_contents("config.api"), true);
$api_key = trim(file_get_contents("pass.txt"));
$prompt_template = file_get_contents("prompt.txt"); // Carga el prompt desde archivo

// Función para formatear el texto de salida
function formatOutput($text) {
    // Convertir caracteres especiales pero preservar el markdown
    $text = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
    
    // Formatear emojis con clase especial
    $text = preg_replace('/([\x{1F300}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}])/u', '<span class="emoji">$1</span>', $text);
    
    // Formatear títulos con emojis
    $text = preg_replace('/📢 ⚡ (.+?)(\n|$)/m', '<div class="section-title"><span class="emoji">📢</span><span class="emoji">⚡</span> $1</div>' . "\n", $text);
    $text = preg_replace('/📌 (.+?):(\n|$)/m', '<div class="section-title"><span class="emoji">📌</span> $1:</div>' . "\n", $text);
    
    // Formatear bloques de código
    $text = preg_replace('/```(\w+)?\n(.*?)```/s', '<pre><code class="language-$1">$2</code></pre>', $text);
    
    // Formatear enlaces
    $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank" class="link">$1</a>', $text);
    
    // Formatear títulos H2 y H3
    $text = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $text);
    $text = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $text);
    
    // Formatear texto en negrita
    $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
    
    // Formatear texto en cursiva
    $text = preg_replace('/\*([^*\n]+)\*/', '<em>$1</em>', $text);
    
    // Formatear listas numeradas
    $text = preg_replace('/^(\d+)\.\s+(.+)$/m', '<li><strong>$1.</strong> $2</li>', $text);
    
    // Formatear listas con checkmark
    $text = preg_replace('/^✅\s+(.+)$/m', '<li class="checked"><span class="emoji">✅</span> $1</li>', $text);
    
    // Formatear listas con guiones
    $text = preg_replace('/^[-•]\s+(.+)$/m', '<li>$1</li>', $text);
    
    // Convertir saltos de línea dobles en separadores de párrafo
    $text = preg_replace('/\n\s*\n/', "\n\n[PARAGRAPH_BREAK]\n\n", $text);
    
    // Dividir el texto en párrafos
    $paragraphs = explode('[PARAGRAPH_BREAK]', $text);
    $formattedParagraphs = [];
    
    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if (empty($paragraph)) continue;
        
        // Si el párrafo contiene elementos de lista, agruparlos
        if (preg_match_all('/<li.*?<\/li>/', $paragraph, $matches)) {
            $listItems = implode("\n", $matches[0]);
            
            // Determinar el tipo de lista
            if (strpos($listItems, '<strong>1.</strong>') !== false) {
                $paragraph = preg_replace('/(<li.*?<\/li>\s*)+/', '<ol>' . $listItems . '</ol>', $paragraph);
            } else if (strpos($listItems, 'class="checked"') !== false) {
                $paragraph = preg_replace('/(<li.*?<\/li>\s*)+/', '<ul class="checklist">' . $listItems . '</ul>', $paragraph);
            } else {
                $paragraph = preg_replace('/(<li.*?<\/li>\s*)+/', '<ul>' . $listItems . '</ul>', $paragraph);
            }
        }
        
        // Si no es un elemento HTML especial, envolver en párrafo
        if (!preg_match('/^<(h[1-6]|div|ul|ol|pre|blockquote)/', $paragraph)) {
            $paragraph = '<p>' . $paragraph . '</p>';
        }
        
        $formattedParagraphs[] = $paragraph;
    }
    
    // Unir párrafos con espaciado
    $text = implode("\n\n", $formattedParagraphs);
    
    // Limpiar espacios en blanco extra
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    
    // Convertir saltos de línea simples restantes a <br>
    $text = preg_replace('/(?<!\n)\n(?!\n)/', '<br>', $text);
    
    return $text;
}

// Verificar si se recibió una pregunta
if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($_POST["pregunta"])) {
    $pregunta = trim($_POST["pregunta"]);

    // Reemplazar la variable en el prompt
    $prompt = str_replace("{{PREGUNTA A ANALIZAR}}", $pregunta, $prompt_template);

    // Preparar datos para la API Cerebras
    $data = [
        "model" => "qwen-3-32b",
        "stream" => false,
        "max_tokens" => $config["max_tokens"] ?? 16382,
        "temperature" => $config["temperature"] ?? 0.7,
        "top_p" => $config["top_p"] ?? 0.95,
        "messages" => [
            ["role" => "system", "content" => ""],
            ["role" => "user", "content" => $prompt]
        ]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.cerebras.ai/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . $api_key
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $response = curl_exec($ch);

    if(curl_errno($ch)) {
        $output = "cURL Error: " . curl_error($ch);
    } else {
        $result = json_decode($response, true);
        $raw_output = $result["choices"][0]["message"]["content"] ?? "Error in AI response.";
        $output = formatOutput($raw_output); // Aplicar formateo aquí
    }
    curl_close($ch);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Synthema IA - Qwen 3-32b</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/styles/atom-one-dark.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #7209b7;
            --light: #f8f9fa;
            --dark: #212529;
            --success: #2dc653;
            --info: #4cc9f0;
            --warning: #f9c74f;
            --danger: #f94144;
            --transition: all 0.3s ease-in-out;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #f6f8ff 0%, #eef1f5 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 2rem;
        }
        
        .container {
            width: 100%;
            max-width: 1000px;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 8px 32px rgba(31, 38, 135, 0.1);
            overflow: hidden;
            transition: var(--transition);
        }
        
        .container:hover {
            box-shadow: 0 8px 32px rgba(31, 38, 135, 0.2);
            transform: translateY(-5px);
        }
        
        h1 {
            text-align: center;
            margin-bottom: 1.5rem;
            color: var(--dark);
            font-weight: 700;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
        }
        
        textarea {
            width: 100%;
            padding: 1rem;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            resize: vertical;
            min-height: 120px;
            font-size: 1rem;
            transition: var(--transition);
            background: rgba(255, 255, 255, 0.8);
        }
        
        textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.1);
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            box-shadow: 0 4px 6px rgba(67, 97, 238, 0.1);
        }
        
        .btn:hover {
            background: var(--secondary);
            transform: translateY(-2px);
            box-shadow: 0 6px 8px rgba(114, 9, 183, 0.2);
        }
        
        .btn i {
            transition: var(--transition);
        }
        
        .btn:hover i {
            transform: rotate(180deg);
        }
        
        .result {
            margin-top: 2rem;
            opacity: 0;
            transform: translateY(20px);
            transition: var(--transition);
        }
        
        .result.show {
            opacity: 1;
            transform: translateY(0);
        }
        
        .result-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--info);
        }
        
        .result-header i {
            color: var(--info);
            font-size: 1.5rem;
        }
        
        .result-content {
            background: var(--dark);
            border-radius: 10px;
            padding: 1.5rem;
            overflow: auto;
            max-height: 400px;
            color: var(--light);
            border-left: 4px solid var(--info);
            line-height: 1.8;
        }
        
        /* Estilos mejorados para el contenido formateado */
        .result-content h2 {
            margin: 1.5rem 0 1rem 0;
            color: var(--info);
            font-size: 1.4rem;
            border-bottom: 2px solid var(--info);
            padding-bottom: 0.5rem;
        }
        
        .result-content h3 {
            margin: 1.2rem 0 0.8rem 0;
            color: var(--info);
            font-size: 1.2rem;
        }
        
        .result-content p {
            margin-bottom: 1.2rem;
            text-align: justify;
        }
        
        .result-content ul, 
        .result-content ol {
            margin: 1rem 0;
            padding-left: 2rem;
        }
        
        .result-content li {
            margin-bottom: 0.8rem;
            line-height: 1.6;
        }
        
        .result-content strong {
            color: var(--info);
            font-weight: 600;
        }
        
        .result-content em {
            font-style: italic;
            color: #adb5bd;
        }
        
        .result-content pre {
            margin: 1.5rem 0;
            border-radius: 8px;
            background: #1a1a1a;
            border: 1px solid #333;
        }
        
        .result-content code {
            border-radius: 8px;
            padding: 1rem;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
        }
        
        .result-content .section-title {
            display: flex;
            align-items: center;
            margin: 1.5rem 0 1rem 0;
            font-weight: bold;
            color: var(--info);
            font-size: 1.1rem;
            padding: 0.5rem;
            background: rgba(76, 201, 240, 0.1);
            border-radius: 8px;
            border-left: 4px solid var(--info);
        }
        
        .result-content .emoji {
            font-size: 1.2em;
            margin-right: 0.3rem;
            vertical-align: middle;
        }
        
        .result-content blockquote {
            border-left: 3px solid var(--info);
            padding-left: 1rem;
            margin: 1rem 0;
            color: #adb5bd;
            font-style: italic;
            background: rgba(76, 201, 240, 0.05);
            padding: 1rem;
            border-radius: 0 8px 8px 0;
        }
        
        .result-content .link {
            color: var(--info);
            text-decoration: none;
            border-bottom: 1px dotted var(--info);
            transition: var(--transition);
        }
        
        .result-content .link:hover {
            border-bottom: 1px solid var(--info);
            background: rgba(76, 201, 240, 0.1);
            padding: 0 2px;
            border-radius: 3px;
        }
        
        .result-content ul.checklist {
            list-style: none;
            padding-left: 1rem;
        }
        
        .result-content ul.checklist li {
            position: relative;
            padding-left: 2rem;
        }
        
        .result-content ul.checklist li .emoji {
            position: absolute;
            left: 0;
            top: 0;
        }
        
        .loading {
            display: none;
            text-align: center;
            margin: 2rem 0;
        }
        
        .loading i {
            font-size: 2rem;
            color: var(--primary);
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .error {
            background: #fdeded;
            border-left: 4px solid var(--danger);
            padding: 1rem;
            border-radius: 10px;
            color: var(--danger);
            margin-top: 1rem;
            display: none;
        }
        
        /* Estilos para dispositivos móviles */
        @media (max-width: 768px) {
            .container {
                padding: 1.5rem;
            }
            
            h1 {
                font-size: 1.5rem;
            }
            
            .result-content {
                max-height: 300px;
                padding: 1rem;
            }
        }
        
        /* Estilos adicionales para el enriquecimiento */
        .result-controls {
            display: flex;
            justify-content: space-between;
            margin-top: 1rem;
            gap: 0.5rem;
        }
        
        .control-btn {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            border: none;
            font-size: 0.9rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            transition: var(--transition);
        }
        
        .copy-btn {
            background: var(--info);
            color: white;
        }
        
        .copy-btn:hover {
            background: #3aa8d9;
        }
        
        .export-btn {
            background: var(--success);
            color: white;
        }
        
        .export-btn:hover {
            background: #25a547;
        }
        
        .theme-toggle {
            background: var(--dark);
            color: white;
        }
        
        .theme-toggle:hover {
            background: #3a3f44;
        }
        
        .theme-light .result-content {
            background: #f8f9fa;
            color: var(--dark);
        }
        
        .theme-light pre code {
            background: #f1f3f5;
            color: #212529;
        }
        
        .word-count {
            margin-top: 0.5rem;
            font-size: 0.9rem;
            color: #6c757d;
            text-align: right;
        }
        
        .toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: var(--dark);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transform: translateY(100px);
            opacity: 0;
            transition: var(--transition);
            z-index: 1000;
        }
        
        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }
        
        .tooltip {
            position: relative;
            display: inline-block;
        }

        .tooltip .tooltiptext {
            visibility: hidden;
            width: 120px;
            background-color: var(--dark);
            color: #fff;
            text-align: center;
            border-radius: 6px;
            padding: 5px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            margin-left: -60px;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .tooltip:hover .tooltiptext {
            visibility: visible;
            opacity: 1;
        }
    </style>
</head>
<body>
    <div class="container animate__animated animate__fadeIn">
        <h1><i class="fas fa-brain"></i> Synthema IA - Qwen 3-32b</h1>
        
        <form method="POST" id="analyzeForm">
            <div class="form-group">
                <label for="pregunta"><i class="fas fa-question-circle"></i> Enter your question:</label>
                <textarea 
                    name="pregunta" 
                    id="pregunta" 
                    rows="4" 
                    placeholder="Type your question to analyze here..."
                    required
                    class="animate__animated animate__fadeIn"
                ><?php echo isset($_POST['pregunta']) ? htmlspecialchars($_POST['pregunta']) : ''; ?></textarea>
                <div class="word-count" id="wordCount">0 characters</div>
            </div>
            
            <button type="submit" class="btn animate__animated animate__pulse animate__infinite">
                <i class="fas fa-bolt"></i> Analyze
            </button>
        </form>
        
        <div class="loading" id="loading">
            <i class="fas fa-spinner"></i>
            <p>Processing your query...</p>
        </div>
        
        <div class="error" id="error">
            <i class="fas fa-exclamation-triangle"></i>
            <span>An error occurred while processing your query.</span>
        </div>
        
        <div class="result" id="result">
            <div class="result-header">
                <i class="fas fa-robot"></i>
                <h2>Analysis Result</h2>
            </div>
            <div class="result-content" id="resultContent">
                <?php
                if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($_POST["pregunta"])) {
                    echo '<div id="output">' . $output . '</div>';
                }
                ?>
            </div>
            
            <div class="result-controls">
                <div>
                    <button id="copyBtn" class="control-btn copy-btn tooltip">
                        <i class="fas fa-copy"></i> Copy
                        <span class="tooltiptext">Copy to clipboard</span>
                    </button>
                    <button id="exportBtn" class="control-btn export-btn tooltip">
                        <i class="fas fa-file-export"></i> Export
                        <span class="tooltiptext">Download as TXT</span>
                    </button>
                </div>
                <button id="themeToggle" class="control-btn theme-toggle tooltip">
                    <i class="fas fa-sun"></i> Toggle Theme
                    <span class="tooltiptext">Switch light/dark mode</span>
                </button>
            </div>
        </div>
        
        <div class="toast" id="toast">
            <i class="fas fa-check-circle"></i>
            <span id="toastMessage">Copied to clipboard</span>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/highlight.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('analyzeForm');
            const loading = document.getElementById('loading');
            const result = document.getElementById('result');
            const resultContent = document.getElementById('resultContent');
            const error = document.getElementById('error');
            const pregunta = document.getElementById('pregunta');
            const wordCount = document.getElementById('wordCount');
            const copyBtn = document.getElementById('copyBtn');
            const exportBtn = document.getElementById('exportBtn');
            const themeToggle = document.getElementById('themeToggle');
            const toast = document.getElementById('toast');
            
            // Contador de palabras
            pregunta.addEventListener('input', function() {
                const count = this.value.length;
                wordCount.textContent = count + (count === 1 ? ' character' : ' characters');
                
                if (count > 2000) {
                    wordCount.style.color = 'var(--danger)';
                } else {
                    wordCount.style.color = '#6c757d';
                }
            });
            
            // Inicializar contador si hay texto
            if (pregunta.value) {
                const count = pregunta.value.length;
                wordCount.textContent = count + (count === 1 ? ' character' : ' characters');
            }
            
            // Botón para copiar resultado
            copyBtn.addEventListener('click', function() {
                const output = document.getElementById('output');
                if (!output) return;
                
                navigator.clipboard.writeText(output.innerText)
                    .then(() => {
                        showToast('Copied to clipboard!');
                    })
                    .catch(err => {
                        showToast('Error copying text', 'error');
                    });
            });
            
            // Botón para exportar resultado
            exportBtn.addEventListener('click', function() {
                const output = document.getElementById('output');
                if (!output) return;
                
                const text = output.innerText;
                const blob = new Blob([text], { type: 'text/plain' });
                const url = URL.createObjectURL(blob);
                
                const a = document.createElement('a');
                a.href = url;
                a.download = 'synthema_ai_analysis_' + new Date().toISOString().slice(0, 10) + '.txt';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                
                showToast('File downloaded successfully');
            });
            
            // Cambiar tema
            themeToggle.addEventListener('click', function() {
                resultContent.classList.toggle('theme-light');
                
                const icon = this.querySelector('i');
                if (icon.classList.contains('fa-sun')) {
                    icon.classList.remove('fa-sun');
                    icon.classList.add('fa-moon');
                } else {
                    icon.classList.remove('fa-moon');
                    icon.classList.add('fa-sun');
                }
            });
            
            // Mostrar toast
            function showToast(message, type = 'success') {
                const toastMessage = document.getElementById('toastMessage');
                toastMessage.textContent = message;
                
                if (type === 'error') {
                    toast.style.backgroundColor = 'var(--danger)';
                } else {
                    toast.style.backgroundColor = 'var(--success)';
                }
                
                toast.classList.add('show');
                
                setTimeout(() => {
                    toast.classList.remove('show');
                }, 3000);
            }
            
            // Mostrar resultado si existe
            <?php if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($_POST["pregunta"])) : ?>
                setTimeout(() => {
                    result.classList.add('show', 'animate__animated', 'animate__fadeInUp');
                    
                    // Aplicar resaltado de código si hay bloques
                    document.querySelectorAll('pre code').forEach((block) => {
                        hljs.highlightElement(block);
                    });
                }, 500);
            <?php endif; ?>
            
            // Manejar envío del formulario con animaciones
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const preguntaValue = document.getElementById('pregunta').value.trim();
                
                if (preguntaValue === '') {
                    document.getElementById('pregunta').classList.add('animate__animated', 'animate__shakeX');
                    setTimeout(() => {
                        document.getElementById('pregunta').classList.remove('animate__animated', 'animate__shakeX');
                    }, 1000);
                    return false;
                }
                
                // Ocultar resultado anterior si existe
                if (result.classList.contains('show')) {
                    result.classList.remove('show');
                    setTimeout(() => {
                        resultContent.innerHTML = '';
                    }, 300);
                }
                
                // Mostrar carga
                loading.style.display = 'block';
                loading.classList.add('animate__animated', 'animate__fadeIn');
                
                // Enviar el formulario después de las animaciones
                setTimeout(() => {
                    form.submit();
                }, 800);
            });
        });
    </script>
</body>
</html>