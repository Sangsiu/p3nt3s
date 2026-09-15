<?php
session_start();
require_once 'generate_report.php'; // Muat konstanta API Key bawaan

$flash_error = isset($_SESSION['flash_error']) ? $_SESSION['flash_error'] : null;
unset($_SESSION['flash_error']);

$has_default_template = file_exists("template.docx");

// Cek status ketersediaan API key bawaan server
$api_keys_status = [
    'groq'       => defined('AI_KEY_GROQ') && !empty(trim(AI_KEY_GROQ)),
    'openai'     => defined('AI_KEY_OPENAI') && !empty(trim(AI_KEY_OPENAI)),
    'gemini'     => defined('AI_KEY_GEMINI') && !empty(trim(AI_KEY_GEMINI)),
    'openrouter' => defined('AI_KEY_OPENROUTER') && !empty(trim(AI_KEY_OPENROUTER)),
];
?>
<!DOCTYPE html>
<html lang="id" class="dark overflow-x-hidden">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sandikami Generator Pentest Report 2026</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS v4 Browser Compiler -->
    <script src="https://unpkg.com/@tailwindcss/browser@4"></script>
    
    <script>
        // Init theme immediately to prevent layout flash
        const currentTheme = localStorage.getItem('theme') || 'dark';
        if (currentTheme === 'dark') {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
        
        // Simpan status API key bawaan server ke JS
        const serverApiKeysStatus = <?php echo json_encode($api_keys_status); ?>;
    </script>
    
    <style type="text/tailwindcss">
        @theme {
            --font-sans: 'Outfit', 'Inter', sans-serif;
        }
        
        /* Enable class-based dark mode in Tailwind v4 */
        @variant dark (&:where(.dark, .dark *));
        
        /* Custom drag & drop styles */
        .dragover {
            @apply border-violet-500/80 bg-violet-500/5 dark:bg-violet-950/10 shadow-lg shadow-violet-500/5 scale-[1.005];
        }
        
        .dragover svg {
            @apply text-violet-500 dark:text-violet-400 scale-110;
        }

        /* Shine reflection effect */
        .btn-shine {
            position: relative;
            overflow: hidden;
        }
        .btn-shine::after {
            content: '';
            position: absolute;
            top: 0;
            left: -150%;
            width: 50%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.45), transparent);
            transform: skewX(-20deg);
            transition: left 0.75s ease;
        }
        .btn-shine:hover::after {
            left: 150%;
        }

        /* Custom Animation Fallbacks (Guarantees rotation & pulse in Dark Mode) */
        @keyframes spin-fallback {
            to {
                transform: rotate(360deg);
            }
        }
        .animate-spin {
            animation: spin-fallback 1s linear infinite !important;
        }

        @keyframes pulse-fallback {
            50% {
                opacity: .5;
            }
        }
        .animate-pulse {
            animation: pulse-fallback 2s cubic-bezier(0.4, 0, 0.6, 1) infinite !important;
        }
    </style>
</head>
<body class="bg-slate-50 dark:bg-[#030712] text-slate-800 dark:text-slate-200 min-h-screen flex flex-col justify-center items-center p-4 md:p-8 font-sans relative overflow-x-hidden selection:bg-violet-500/30 transition-colors duration-300">

    <!-- Theme Toggle Button -->
    <button id="theme-toggle" class="fixed top-4 right-4 p-2.5 rounded-full border border-slate-200 dark:border-slate-800 bg-white/80 dark:bg-slate-900/30 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all duration-300 shadow-md backdrop-blur-md cursor-pointer z-50 flex items-center justify-center" aria-label="Toggle Theme">
        <!-- Sun Icon (shown in dark mode) -->
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 hidden dark:block text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m0-12.728l.707.707m12.728 12.728l.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z" />
        </svg>
        <!-- Moon Icon (shown in light mode) -->
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 block dark:hidden text-slate-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
        </svg>
    </button>

    <!-- Glowing Background Accents Wrapper (Guarantees no horizontal overflow/scrolling on mobile) -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none -z-10">
        <div class="absolute -top-40 -left-40 w-96 h-96 bg-violet-600/5 dark:bg-violet-600/5 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-40 -right-40 w-96 h-96 bg-blue-600/5 dark:bg-blue-600/5 rounded-full blur-3xl"></div>
    </div>

    <!-- Spinner Loading Overlay -->
    <div class="fixed inset-0 bg-white/80 dark:bg-gray-950/80 backdrop-blur-md z-50 flex flex-col items-center justify-center gap-4 hidden" id="loader">
        <div class="w-12 h-12 border-4 border-slate-200 dark:border-slate-800 border-t-violet-500 rounded-full animate-spin"></div>
        <div class="text-lg font-semibold text-slate-800 dark:text-slate-200 tracking-wide animate-pulse" id="loader-text">Memproses XML & Menyusun Laporan...</div>
    </div>

    <div class="w-full max-w-3xl z-10 space-y-6">
        <!-- Header -->
        <header class="text-center space-y-2">
            <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight bg-gradient-to-r from-slate-900 via-slate-800 to-slate-600 dark:from-white dark:via-slate-200 dark:to-slate-400 bg-clip-text text-transparent">
                Sandikami Generator Pentest Report 2026
            </h1>
            <p class="text-slate-500 dark:text-slate-400 text-xs md:text-sm max-w-md mx-auto font-light leading-relaxed">
                Created by Sangsiu 2026
            </p>
        </header>

        <!-- Glassmorphism Card -->
        <div class="bg-white/80 dark:bg-slate-900/30 backdrop-blur-xl border border-slate-200 dark:border-slate-800/80 rounded-2xl p-4 sm:p-6 md:p-8 shadow-xl dark:shadow-2xl relative overflow-hidden transition-all duration-300">
            <!-- Neon top line border -->
            <div class="absolute top-0 left-0 right-0 h-[2px] bg-gradient-to-r from-violet-500 via-fuchsia-500 to-blue-500"></div>

            <!-- Card Header Section -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 pb-5 mb-5 border-b border-slate-200/60 dark:border-slate-800/60">
                <h2 class="text-sm font-bold text-slate-800 dark:text-slate-200 uppercase tracking-wider">Konfigurasi Laporan</h2>
                
                <!-- Dynamic Template Status Badge -->
                <?php if ($has_default_template): ?>
                    <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-[10px] font-medium">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 dark:bg-emerald-400 animate-pulse"></span>
                        Template: (Tersedia)
                    </div>
                <?php else: ?>
                    <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-red-500/10 border border-red-500/20 text-red-600 dark:text-red-400 text-[10px] font-medium">
                        <span class="w-1.5 h-1.5 rounded-full bg-red-500 dark:bg-red-400"></span>
                        Template: (Hilang)
                    </div>
                <?php endif; ?>
            </div>

            <!-- Client side alert zone -->
            <div class="flex items-center gap-3 p-3.5 rounded-xl border text-sm bg-red-50/80 dark:bg-red-950/20 border-red-200 dark:border-red-900/30 text-red-700 dark:text-red-300 mb-6 hidden" id="client-error-alert">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <span id="client-error-message">Error message placeholder</span>
            </div>

            <!-- Client side success alert zone -->
            <div class="flex items-center gap-3 p-3.5 rounded-xl border text-sm bg-emerald-50/80 dark:bg-emerald-950/20 border-emerald-200 dark:border-emerald-900/30 text-emerald-700 dark:text-emerald-300 mb-6 hidden" id="client-success-alert">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span id="client-success-message">Success message placeholder</span>
            </div>

            <?php if (!$has_default_template): ?>
                <div class="flex items-start gap-3 p-3.5 rounded-xl border text-sm bg-amber-50/80 dark:bg-amber-950/20 border-amber-200 dark:border-amber-900/30 text-amber-700 dark:text-amber-300 mb-6">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <span>File <strong class="text-amber-800 dark:text-amber-200">template.docx</strong> tidak ditemukan di folder aplikasi. Pastikan template.docx diletakkan di direktori yang sama dengan berkas PHP ini sebelum mengompilasi.</span>
                </div>
            <?php endif; ?>

            <?php if ($flash_error): ?>
                <div class="flex items-center gap-3 p-3.5 rounded-xl border text-sm bg-red-50/80 dark:bg-red-950/20 border-red-200 dark:border-red-900/30 text-red-700 dark:text-red-300 mb-6">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <span><?php echo htmlspecialchars($flash_error); ?></span>
                </div>
            <?php endif; ?>

            <form id="generator-form" action="generate.php" method="POST" enctype="multipart/form-data">
                
                <!-- Dual Column Layout: Upload (Left) & Inputs (Right) -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-stretch">
                    
                    <!-- Left Column: File Upload -->
                    <div class="flex flex-col h-full space-y-3">
                        <div class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
                            <span class="flex items-center justify-center w-5 h-5 rounded-full bg-violet-500/10 text-violet-600 dark:text-violet-400 text-[10px] font-bold">1</span>
                            Berkas Hasil Scan
                        </div>
                        
                        <div class="border-2 border-dashed border-slate-200 dark:border-slate-800 hover:border-violet-500/40 dark:hover:border-violet-500/40 bg-slate-50/50 hover:bg-slate-100/50 dark:bg-slate-950/20 dark:hover:bg-slate-950/40 rounded-xl p-6 transition-all duration-300 cursor-pointer flex flex-col items-center justify-center flex-grow min-h-[240px] md:min-h-0 text-center relative group" id="xml-drop-zone">
                            <!-- Cloud Upload Icon -->
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-slate-400 dark:text-slate-500 group-hover:text-violet-500 dark:group-hover:text-violet-400 transition-all duration-300 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                            </svg>
                            <span class="text-xs font-bold text-slate-600 dark:text-slate-300 group-hover:text-slate-800 dark:group-hover:text-white transition-colors duration-300">
                                Seret & Lepas File XML / HTML
                            </span>
                            <span class="text-[10px] text-slate-400 dark:text-slate-500 mt-1 max-w-[200px] leading-relaxed">
                                Dukungan file XML / HTML standar Burp Suite atau DocBook
                            </span>
                            <input type="file" name="xml_file" id="xml_file" accept=".xml,.html,.htm" class="hidden" required>
                            
                            <!-- Refined File Info Indicator Box -->
                            <div class="mt-4 flex items-center gap-2 p-2 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-[11px] font-medium max-w-full w-full justify-center hidden" id="xml-file-info">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span class="file-name truncate max-w-[140px] sm:max-w-[200px]">file_name.xml</span>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Form Inputs -->
                    <div class="flex flex-col h-full space-y-3">
                        <div class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
                            <span class="flex items-center justify-center w-5 h-5 rounded-full bg-blue-500/10 text-blue-500 dark:text-blue-400 text-[10px] font-bold">2</span>
                            Parameter Laporan
                        </div>
                        
                        <div class="flex flex-col gap-4 flex-grow justify-between bg-slate-50/50 border border-slate-200 dark:bg-slate-950/10 dark:border-slate-800/40 rounded-xl p-4 md:p-5">
                            
                            <div class="space-y-1">
                                <label for="opd_name" class="text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                    Nama OPD / Instansi
                                </label>
                                <input type="text" id="opd_name" name="opd_name" placeholder="Otomatis dideteksi sistem" 
                                       class="w-full px-3.5 py-2 rounded-lg bg-white dark:bg-slate-950/40 hover:bg-slate-50/30 dark:hover:bg-slate-950/60 border border-slate-200 dark:border-slate-800 focus:border-violet-500 dark:focus:border-violet-500/60 focus:ring-1 focus:ring-violet-500 text-slate-800 dark:text-slate-100 text-xs placeholder-slate-400 dark:placeholder-slate-700 transition-all duration-300">
                                <p class="text-[9px] text-slate-400 dark:text-slate-600 leading-normal">
                                    Mendeteksi kata "bcc" (Dinas Tenaga Kerja) atau "kesbangpol" (Bakesbangpol).
                                </p>
                            </div>

                            <div class="space-y-1">
                                <label for="nomor_surat" class="text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                    Nomor Surat
                                </label>
                                <input type="text" id="nomor_surat" name="nomor_surat" placeholder="Otomatis disesuaikan" 
                                       class="w-full px-3.5 py-2 rounded-lg bg-white dark:bg-slate-950/40 hover:bg-slate-50/30 dark:hover:bg-slate-950/60 border border-slate-200 dark:border-slate-800 focus:border-violet-500 dark:focus:border-violet-500/60 focus:ring-1 focus:ring-violet-500 text-slate-800 dark:text-slate-100 text-xs placeholder-slate-400 dark:placeholder-slate-700 transition-all duration-300">
                                <p class="text-[9px] text-slate-400 dark:text-slate-600 leading-normal">
                                    Default: 1422 untuk file BCC, 1421 untuk file Kesbangpol.
                                </p>
                            </div>

                            <div class="space-y-1">
                                <label for="app_name" class="text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                    Nama Aplikasi
                                </label>
                                <input type="text" id="app_name" name="app_name" placeholder="Diekstrak dari respon XML" 
                                       class="w-full px-3.5 py-2 rounded-lg bg-white dark:bg-slate-950/40 hover:bg-slate-50/30 dark:hover:bg-slate-950/60 border border-slate-200 dark:border-slate-800 focus:border-violet-500 dark:focus:border-violet-500/60 focus:ring-1 focus:ring-violet-500 text-slate-800 dark:text-slate-100 text-xs placeholder-slate-400 dark:placeholder-slate-700 transition-all duration-300">
                                <p class="text-[9px] text-slate-400 dark:text-slate-600 leading-normal">
                                    Membaca tag HTML title di dalam respon HTTP secara otomatis.
                                </p>
                            </div>

                            <!-- AI Enhancement Card (Premium Features) -->
                            <div class="mt-2 pt-3 border-t border-slate-200 dark:border-slate-800/40 space-y-3">
                                <label class="relative flex items-center gap-3 cursor-pointer group select-none">
                                    <input type="checkbox" id="use_ai" name="use_ai" value="1" class="sr-only peer">
                                    <div class="w-8 h-4.5 bg-slate-200 dark:bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-3.5 after:w-3.5 after:transition-all dark:border-slate-600 peer-checked:bg-violet-600"></div>
                                    <span class="text-[11px] font-bold text-slate-500 dark:text-slate-400 group-hover:text-slate-700 dark:group-hover:text-slate-200 uppercase tracking-wider transition-colors duration-300 flex items-center gap-1">
                                        Optimasi AI Remediasi 🤖
                                    </span>
                                </label>

                                <label class="relative flex items-center gap-3 cursor-pointer group select-none mt-2">
                                    <input type="checkbox" id="use_custom_api" name="use_custom_api" value="1" class="sr-only peer">
                                    <div class="w-8 h-4.5 bg-slate-200 dark:bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-3.5 after:w-3.5 after:transition-all dark:border-slate-600 peer-checked:bg-violet-600"></div>
                                    <span class="text-[11px] font-bold text-slate-500 dark:text-slate-400 group-hover:text-slate-700 dark:group-hover:text-slate-200 uppercase tracking-wider transition-colors duration-300 flex items-center gap-1">
                                        Kustom API Key & Model 🔑
                                    </span>
                                </label>
                                
                                <!-- Configuration Container: shown when EITHER toggle is active -->
                                <div id="ai_config_box" class="hidden space-y-3 mt-2 p-3.5 bg-violet-500/5 border border-violet-500/10 dark:border-violet-500/20 rounded-xl transition-all duration-300">
                                    <div class="space-y-1">
                                        <label for="ai_provider" class="text-[9px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                            Pilih Provider AI
                                        </label>
                                        <select id="ai_provider" name="ai_provider" 
                                                class="w-full px-2 py-1.5 rounded-lg bg-white dark:bg-slate-950/40 border border-slate-200 dark:border-slate-800 focus:border-violet-500 text-[10px] text-slate-800 dark:text-slate-100 transition-all duration-300 cursor-pointer">
                                            <option value="groq" selected>Groq (Default - Compound)</option>
                                            <option value="openai">OpenAI (GPT-4o-mini)</option>
                                            <option value="gemini">Google Gemini (Gemini-1.5-flash)</option>
                                            <option value="openrouter">OpenRouter (Llama / Mistral)</option>
                                        </select>
                                    </div>
                                    
                                    <div class="space-y-1">
                                        <div class="flex items-center justify-between gap-2">
                                            <label for="ai_model" class="text-[9px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                                Pilih Model AI
                                            </label>
                                            <button type="button" id="btn-refresh-models" class="shrink-0 inline-flex items-center gap-1 px-2 py-1 rounded-md border border-violet-500/20 bg-violet-500/10 hover:bg-violet-500/20 text-violet-600 dark:text-violet-300 text-[8px] font-bold uppercase tracking-wider transition-all duration-300 cursor-pointer">
                                                <span id="btn-refresh-icon">🔄</span> Update Model Terbaru
                                            </button>
                                        </div>
                                        <select id="ai_model" name="ai_model" 
                                                class="w-full px-2 py-1.5 rounded-lg bg-white dark:bg-slate-950/40 border border-slate-200 dark:border-slate-800 focus:border-violet-500 text-[10px] text-slate-800 dark:text-slate-100 transition-all duration-300 cursor-pointer">
                                            <!-- Dynamically populated via JS (cache + live) -->
                                        </select>
                                        <div class="flex items-center justify-between gap-2">
                                            <span id="model-updated-at" class="text-[8px] text-slate-400 dark:text-slate-500 font-light"></span>
                                            <span id="model-refresh-status" class="text-[8px] font-medium hidden"></span>
                                        </div>
                                    </div>

                                    <div id="custom_model_box" class="space-y-1 hidden">
                                        <label for="custom_model" class="text-[9px] font-bold text-violet-600 dark:text-violet-400 uppercase tracking-wider">
                                            ID Model Kustom
                                        </label>
                                        <input type="text" id="custom_model" name="custom_model" placeholder="Contoh: deepseek-coder atau gpt-4-turbo" 
                                               class="w-full px-3 py-1.5 rounded-lg bg-white dark:bg-slate-950/40 border border-slate-200 dark:border-slate-800 focus:border-violet-500 text-[10px] text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-700 transition-all duration-300">
                                    </div>

                                    <div class="space-y-1">
                                        <label for="ai_preset" class="text-[9px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                            Fokus Bahasa & Contoh Kode
                                        </label>
                                        <select id="ai_preset" name="ai_preset" 
                                                class="w-full px-2 py-1.5 rounded-lg bg-white dark:bg-slate-950/40 border border-slate-200 dark:border-slate-800 focus:border-violet-500 text-[10px] text-slate-800 dark:text-slate-100 transition-all duration-300 cursor-pointer">
                                            <option value="php">PHP (PDO/Laravel)</option>
                                            <option value="nodejs">JavaScript (NodeJS/Express)</option>
                                            <option value="python">Python (Django/Flask)</option>
                                            <option value="java">Java (Spring Boot)</option>
                                            <option value="golang">Go (Golang/Gin)</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Vault: kelola kunci rotasi (muncul untuk semua provider) -->
                                    <div id="keys_vault_box" class="space-y-2 pt-3 border-t border-violet-500/10 dark:border-violet-500/20">
                                        <div class="flex items-center justify-between gap-2">
                                            <label class="text-[9px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider flex items-center gap-1">
                                                Kelola API Key (Rotasi Otomatis) 🔐
                                            </label>
                                            <span id="vault-count" class="text-[8px] font-medium text-slate-400 dark:text-slate-500"></span>
                                        </div>
                                        <div id="vault-list" class="space-y-1 max-h-32 overflow-y-auto pr-1"></div>
                                        <div class="flex gap-2">
                                            <input type="password" id="vault_new_key" placeholder="gsk_... (Groq wajib gsk_)" 
                                                   class="flex-1 min-w-0 px-3 py-1.5 rounded-lg bg-white dark:bg-slate-950/40 border border-slate-200 dark:border-slate-800 focus:border-violet-500 text-[10px] text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-700 transition-all duration-300">
                                            <button type="button" id="btn-vault-add" 
                                                    class="shrink-0 px-3 py-1.5 rounded-md bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-[9px] uppercase tracking-wider transition-all duration-300 cursor-pointer whitespace-nowrap">
                                                + Tambah Key
                                            </button>
                                        </div>
                                        <div id="vault-status" class="text-[8px] font-medium hidden"></div>
                                        <p class="text-[7px] text-slate-400 dark:text-slate-600 leading-normal">
                                            Duplikat otomatis ditolak. Disimpan terenkripsi AES-256 di <code>data/cache/keys.json</code>. Tiap key divalidasi live ke <code>api.groq.com/openai/v1/models</code> sebelum disimpan.
                                        </p>
                                    </div>

                                    <!-- Custom Key Container: ONLY shown when use_custom_api is checked -->
                                    <div id="custom_key_container" class="hidden space-y-3 pt-2 border-t border-violet-500/10 dark:border-violet-500/20">
                                        <div class="space-y-1">
                                            <label for="custom_api_key" class="text-[9px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                                Custom API Key (Sekali Pakai)
                                            </label>
                                            <input type="password" id="custom_api_key" name="custom_api_key" placeholder="Kosongkan untuk menggunakan kunci default server" 
                                                   class="w-full px-3 py-1.5 rounded-lg bg-white dark:bg-slate-950/40 border border-slate-200 dark:border-slate-800 focus:border-violet-500 text-[10px] text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-700 transition-all duration-300">
                                        </div>
                                        
                                        <!-- Connection test button and indicator status -->
                                        <div class="flex items-center justify-between gap-2 pt-1">
                                            <button type="button" id="btn-test-connection" 
                                                    class="px-2.5 py-1.5 rounded-md bg-violet-600 hover:bg-violet-500 text-white font-bold text-[9px] uppercase tracking-wider transition-all duration-300 cursor-pointer flex items-center gap-1 shadow-sm">
                                                Uji Koneksi API ⚡
                                            </button>
                                            <div id="conn-test-status" class="text-[9px] font-medium text-slate-400 hidden">
                                                Memeriksa...
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <p class="text-[8px] text-slate-400 dark:text-slate-600 leading-normal font-light">
                                        Jika Custom API Key kosong, sistem pakai vault + kunci server (rotasi otomatis 429 → key berikutnya).
                                    </p>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- Cache Management Bar -->
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 p-3 bg-slate-50/80 dark:bg-slate-950/20 border border-slate-200 dark:border-slate-800/40 rounded-xl mt-4">
                    <div class="flex items-center gap-2 text-[10px] text-slate-500 dark:text-slate-400">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-violet-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.58 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.58 4 8 4s8-1.79 8-4M4 7c0-2.21 3.58-4 8-4s8 1.79 8 4m0 5c0 2.21-3.58 4-8 4s-8-1.79-8-4" />
                        </svg>
                        <span>
                            Cache Terjemahan: <strong id="translate-cache-size" class="text-slate-700 dark:text-slate-200">Checking...</strong> | 
                            Cache AI: <strong id="ai-cache-size" class="text-slate-700 dark:text-slate-200">Checking...</strong>
                        </span>
                    </div>
                    <button type="button" id="btn-clear-cache" 
                            class="px-2.5 py-1.5 rounded-md border border-red-500/20 bg-red-500/5 hover:bg-red-500/10 text-red-600 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 font-bold text-[9px] uppercase tracking-wider transition-all duration-300 cursor-pointer flex items-center justify-center gap-1">
                        Bersihkan Cache 🧹
                    </button>
                </div>

                <!-- Sleek Divider Line -->
                <div class="relative my-6">
                    <div class="absolute inset-0 flex items-center" aria-hidden="true">
                        <div class="w-full border-t border-slate-200 dark:border-slate-800/60"></div>
                    </div>
                </div>

                <!-- Submit Button with Ambient Glow & Inner Shine Reflection -->
                <div class="relative w-full">
                    <button type="submit" class="peer relative overflow-hidden w-full py-3 rounded-lg bg-gradient-to-r from-violet-600 to-blue-600 hover:from-violet-500 hover:to-blue-500 text-white font-bold text-xs uppercase tracking-wider hover:-translate-y-0.5 active:translate-y-0 transition-all duration-300 disabled:from-slate-200 dark:disabled:from-slate-800 disabled:to-slate-200 dark:disabled:to-slate-800 disabled:text-slate-400 dark:disabled:text-slate-500 disabled:cursor-not-allowed flex items-center justify-center gap-2 cursor-pointer btn-shine" 
                            id="submit-btn" <?php echo !$has_default_template ? 'disabled' : ''; ?>>
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        Kompilasi & Unduh Laporan
                    </button>
                    
                    <!-- Ambient Glow Effect behind the button -->
                    <div class="absolute -inset-0.5 bg-gradient-to-r from-violet-600 to-blue-600 rounded-lg blur-md opacity-25 transition duration-300 -z-10 peer-hover:opacity-75 peer-disabled:opacity-0 pointer-events-none"></div>
                </div>

            </form>
        </div>

        <!-- Footer -->
        <footer class="text-center text-[10px] text-slate-400 dark:text-slate-600 pt-2 tracking-wide transition-colors duration-300">
            <p>&copy; 2026 Sandikami Generator Pentest Report. Created by Sangsiu.</p>
        </footer>
    </div>

    <script>
        const clientErrorAlert = document.getElementById('client-error-alert');
        const clientErrorMessage = document.getElementById('client-error-message');

        // Theme Toggle script
        const themeToggleBtn = document.getElementById('theme-toggle');
        
        themeToggleBtn.addEventListener('click', () => {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('theme', 'light');
            } else {
                document.documentElement.classList.add('dark');
                localStorage.setItem('theme', 'dark');
            }
        });

        const clientSuccessAlert = document.getElementById('client-success-alert');
        const clientSuccessMessage = document.getElementById('client-success-message');

        function showClientSuccess(msg) {
            clientSuccessMessage.textContent = msg;
            clientSuccessAlert.classList.remove('hidden');
            clientErrorAlert.classList.add('hidden');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function clearClientSuccess() {
            clientSuccessAlert.classList.add('hidden');
        }

        function showClientError(msg) {
            clientErrorMessage.textContent = msg;
            clientErrorAlert.classList.remove('hidden');
            clientSuccessAlert.classList.add('hidden');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function clearClientError() {
            clientErrorAlert.classList.add('hidden');
            clearClientSuccess();
        }

        // Setup Drag and Drop logic
        function initDragAndDrop(zoneId, inputId, infoId) {
            const zone = document.getElementById(zoneId);
            const input = document.getElementById(inputId);
            const info = document.getElementById(infoId);
            const fileNameText = info.querySelector('.file-name');

            zone.addEventListener('click', () => input.click());

            zone.addEventListener('dragover', (e) => {
                e.preventDefault();
                zone.classList.add('dragover');
            });

            zone.addEventListener('dragleave', () => {
                zone.classList.remove('dragover');
            });

            zone.addEventListener('drop', (e) => {
                e.preventDefault();
                zone.classList.remove('dragover');
                
                if (e.dataTransfer.files.length) {
                    const file = e.dataTransfer.files[0];
                    if (validateFile(file)) {
                        input.files = e.dataTransfer.files;
                        updateFileInfo(file.name, info);
                        autoFillParameters(file.name);
                    }
                }
            });

            input.addEventListener('change', () => {
                if (input.files.length) {
                    const file = input.files[0];
                    if (validateFile(file)) {
                        updateFileInfo(file.name, info);
                        autoFillParameters(file.name);
                    } else {
                        input.value = ""; // Reset file selection
                        info.classList.add('hidden');
                    }
                }
            });
        }

        function validateFile(file) {
            clearClientError();
            const ext = file.name.toLowerCase();
            if (!ext.endsWith('.xml') && !ext.endsWith('.html') && !ext.endsWith('.htm')) {
                showClientError("Berkas yang Anda pilih bukan format yang didukung! Silakan pilih file XML (.xml) atau HTML (.html/.htm) hasil scan Burp Suite.");
                return false;
            }
            return true;
        }

        function updateFileInfo(name, infoElement) {
            const fileNameText = infoElement.querySelector('.file-name');
            fileNameText.textContent = name;
            infoElement.classList.remove('hidden');
            infoElement.classList.add('flex');
        }

        function autoFillParameters(fileName) {
            const nameLower = fileName.toLowerCase();
            const opdInput = document.getElementById('opd_name');
            const nomorInput = document.getElementById('nomor_surat');
            const appInput = document.getElementById('app_name');
            
            // 1. Auto-detect OPD & Nomor Surat
            if (nameLower.includes('bcc') || nameLower.includes('disnaker') || nameLower.includes('career')) {
                opdInput.value = "Dinas Tenaga Kerja";
                nomorInput.value = "1422";
            } else if (nameLower.includes('bakesbangpol') || nameLower.includes('kesbangpol')) {
                opdInput.value = "Badan Kesatuan Bangsa dan Politik";
                nomorInput.value = "1421";
            } else {
                opdInput.value = "";
                nomorInput.value = "";
            }
            
            // 2. Auto-detect and suggest App Name from filename
            let cleanName = fileName.substring(0, fileName.lastIndexOf('.')) || fileName;
            // Remove dates: "10 agustus 2026", "2026-08-12", "12-08-2026"
            cleanName = cleanName.replace(/(\d{1,2})\s+([a-zA-Z]+)\s+(\d{4})/gi, '');
            cleanName = cleanName.replace(/\d{4}[-._]\d{2}[-._]\d{2}/g, '');
            cleanName = cleanName.replace(/\d{2}[-._]\d{2}[-._]\d{4}/g, '');
            // Remove keywords
            cleanName = cleanName.replace(/\b(scan|report|burp|pentest|hasil|pengujian|v3|v2|v1)\b/gi, '');
            // Clean non-alphanumeric
            cleanName = cleanName.replace(/[^a-zA-Z0-9\s-]/g, ' ');
            cleanName = cleanName.replace(/\s+/g, ' ');
            cleanName = cleanName.trim();
            
            // Capitalize to Title Case
            if (cleanName) {
                appInput.value = cleanName.replace(/\w\S*/g, (w) => (w.replace(/^\w/, (c) => c.toUpperCase())));
            } else {
                appInput.value = "";
            }
        }

        // Initialize drag & drop zones
        initDragAndDrop('xml-drop-zone', 'xml_file', 'xml-file-info');

        const form = document.getElementById('generator-form');
        const loader = document.getElementById('loader');
        const loaderText = document.getElementById('loader-text');
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const xmlInput = document.getElementById('xml_file');
            if (xmlInput.files.length === 0) {
                if (!form.reportValidity()) return;
                showClientError('File XML/HTML scanner wajib diisi.');
                return;
            }
            clearClientError(); clearClientSuccess();
            loader.classList.remove('hidden'); loader.classList.add('flex');
            if (loaderText) loaderText.textContent = 'Memproses & Menyusun Laporan...';
            const fd = new FormData(form);
            fd.set('ajax', '1');
            try {
                const res = await fetch('generate.php', { method: 'POST', body: fd });
                const ct = (res.headers.get('Content-Type') || '').toLowerCase();
                if (!res.ok) {
                    let msg = 'Gagal memproses laporan.';
                    if (ct.includes('application/json')) { try { const j = await res.json(); msg = j.message || msg; } catch(_){} }
                    else { const t = await res.text(); if (t && t.length < 800) msg = t.replace(/<[^>]+>/g,'').trim().slice(0,300) || msg; }
                    throw new Error(msg);
                }
                if (ct.includes('application/json')) {
                    let j; try { j = await res.json(); } catch(_) { j = null; }
                    if (j && j.status === 'error') throw new Error(j.message || 'Gagal memproses laporan.');
                    throw new Error((j && j.message) || 'Gagal memproses laporan.');
                }
                const blob = await res.blob();
                if (blob.size < 1024) throw new Error('File hasil terlalu kecil / gagal generate.');
                // DOCX adalah ZIP dan wajib diawali signature PK. Jangan menyimpan
                // halaman error HTML sebagai file .docx bila proses AI gagal.
                const signature = new Uint8Array(await blob.slice(0, 2).arrayBuffer());
                if (signature.length < 2 || signature[0] !== 0x50 || signature[1] !== 0x4b) {
                    throw new Error('Server tidak mengirim file Word yang valid. Periksa API AI atau log server.');
                }
                const disp = res.headers.get('Content-Disposition') || '';
                let filename = 'Laporan_Pentest.docx';
                const m = disp.match(/filename\*?=(?:UTF-8''|")?([^";\n]+)"?/i);
                if (m && m[1]) { try { filename = decodeURIComponent(m[1].replace(/^"|"$/g,'')); } catch(_) { filename = m[1]; } }
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a'); a.href = url; a.download = filename;
                document.body.appendChild(a); a.click(); a.remove();
                setTimeout(() => URL.revokeObjectURL(url), 1200);
                const isAi = document.getElementById('use_ai').checked || document.getElementById('use_custom_api').checked;
                showClientSuccess(isAi ? 'Laporan berhasil dikompilasi dengan optimasi AI dan berkas Word sedang diunduh!' : 'Laporan berhasil dikompilasi dan berkas Word sedang diunduh!');
            } catch (err) {
                showClientError(err.message || 'Gagal memproses laporan.');
            } finally {
                loader.classList.add('hidden'); loader.classList.remove('flex');
                if (loaderText) loaderText.textContent = 'Memproses XML & Menyusun Laporan...';
            }
        });

        // Toggle AI Config Visibility dengan logika sakelar saling mengecualikan (mutually exclusive)
        const useAiToggle = document.getElementById('use_ai');
        const useCustomApiToggle = document.getElementById('use_custom_api');
        const aiConfigBox = document.getElementById('ai_config_box');
        const customKeyContainer = document.getElementById('custom_key_container');
        
        function updateProviderStatus() {
            const providerSelect = document.getElementById('ai_provider');
            const options = providerSelect.options;
            const isCustom = useCustomApiToggle.checked;

            const providerLabels = {
                groq: 'Groq (Default - Compound)',
                openai: 'OpenAI (GPT-4o-mini)',
                gemini: 'Google Gemini (Gemini-1.5-flash)',
                openrouter: 'OpenRouter (Llama / Mistral)'
            };

            for (let i = 0; i < options.length; i++) {
                const opt = options[i];
                const provider = opt.value;
                const isConfigured = serverApiKeysStatus[provider] === true;

                if (isCustom) {
                    opt.disabled = false;
                    opt.textContent = providerLabels[provider];
                } else {
                    if (isConfigured) {
                        opt.disabled = false;
                        opt.textContent = providerLabels[provider];
                    } else {
                        opt.disabled = true;
                        opt.textContent = providerLabels[provider] + ' (Belum Dikonfigurasi)';
                        
                        // Jika provider yang sedang dipilih dinonaktifkan, pindahkan ke Groq (yang defaultnya aktif)
                        if (providerSelect.value === provider) {
                            providerSelect.value = 'groq';
                            updateModelOptions();
                        }
                    }
                }
            }
        }

        function updateBoxesVisibility() {
            // Tampilkan config box jika salah satu toggle aktif
            if (useAiToggle.checked || useCustomApiToggle.checked) {
                aiConfigBox.classList.remove('hidden');
                aiConfigBox.classList.add('block');
            } else {
                aiConfigBox.classList.remove('block');
                aiConfigBox.classList.add('hidden');
            }

            // Tampilkan custom API key container hanya jika toggle custom aktif
            const customApiKeyInput = document.getElementById('custom_api_key');
            if (useCustomApiToggle.checked) {
                customKeyContainer.classList.remove('hidden');
                customKeyContainer.classList.add('block');
                customApiKeyInput.required = true;
            } else {
                customKeyContainer.classList.remove('block');
                customKeyContainer.classList.add('hidden');
                customApiKeyInput.required = false;
                customApiKeyInput.value = ''; // Reset ketika dinonaktifkan
            }

            updateProviderStatus();
        }

        useAiToggle.addEventListener('change', () => {
            if (useAiToggle.checked) {
                useCustomApiToggle.checked = false;
            }
            updateBoxesVisibility();
        });

        useCustomApiToggle.addEventListener('change', () => {
            if (useCustomApiToggle.checked) {
                useAiToggle.checked = false;
            }
            updateBoxesVisibility();
        });

        const providerModels = {
            groq: [
                { value: 'groq/compound', text: 'Groq Compound (Default)' },
                { value: 'groq/compound-mini', text: 'Groq Compound Mini' },
                { value: 'qwen/qwen3-32b', text: 'Qwen 3 32B' },
                { value: 'openai/gpt-oss-120b', text: 'GPT OSS 120B' },
                { value: 'openai/gpt-oss-20b', text: 'GPT OSS 20B' },
                { value: 'llama-3.3-70b-versatile', text: 'Llama 3.3 70B Versatile' },
                { value: 'llama-3.1-8b-instant', text: 'Llama 3.1 8B Instant' },
                { value: 'custom', text: 'Model Lainnya (Tulis Kustom...)' }
            ],
            openai: [
                { value: 'gpt-4o-mini', text: 'GPT-4o-mini (Default)' },
                { value: 'gpt-4o', text: 'GPT-4o' },
                { value: 'gpt-4.1-mini', text: 'GPT-4.1 mini' },
                { value: 'o3-mini', text: 'O3 Mini' },
                { value: 'custom', text: 'Model Lainnya (Tulis Kustom...)' }
            ],
            gemini: [
                { value: 'gemini-1.5-flash', text: 'Gemini-1.5-flash (Default)' },
                { value: 'gemini-1.5-pro', text: 'Gemini-1.5-pro' },
                { value: 'gemini-2.0-flash', text: 'Gemini-2.0-flash' },
                { value: 'custom', text: 'Model Lainnya (Tulis Kustom...)' }
            ],
            openrouter: [
                { value: 'meta-llama/llama-3.3-70b-instruct', text: 'Llama-3.3-70b (Default)' },
                { value: 'google/gemini-2.0-flash-exp:free', text: 'Gemini-2.0-flash (Free)' },
                { value: 'deepseek/deepseek-chat', text: 'DeepSeek Chat (V3)' },
                { value: 'custom', text: 'Model Lainnya (Tulis Kustom...)' }
            ]
        };

        const providerSelect = document.getElementById('ai_provider');
        const modelSelect = document.getElementById('ai_model');
        const customModelBox = document.getElementById('custom_model_box');
        const customModelInput = document.getElementById('custom_model');
        const btnRefreshModels = document.getElementById('btn-refresh-models');
        const modelUpdatedAt = document.getElementById('model-updated-at');
        const modelRefreshStatus = document.getElementById('model-refresh-status');
        let cachedModels = null;
        let cachedUpdatedAt = {};

        function getModelsFor(provider) {
            if (cachedModels && cachedModels[provider] && Array.isArray(cachedModels[provider]) && cachedModels[provider].length) return cachedModels[provider];
            return providerModels[provider] || [];
        }

        function renderModelUpdatedAt(provider) {
            if (!modelUpdatedAt) return;
            const ts = cachedUpdatedAt[provider] || null;
            if (ts) {
                try { const d = new Date(ts); modelUpdatedAt.textContent = 'Update: ' + d.toLocaleString('id-ID'); } catch(e) { modelUpdatedAt.textContent = 'Update: ' + ts; }
            } else {
                modelUpdatedAt.textContent = cachedModels ? 'Menggunakan daftar tersimpan' : '';
            }
        }

        function setModelRefreshStatus(msg, ok) {
            if (!modelRefreshStatus) return;
            modelRefreshStatus.textContent = msg;
            modelRefreshStatus.classList.remove('hidden', 'text-emerald-500', 'text-red-500', 'text-slate-400');
            modelRefreshStatus.classList.add(ok ? 'text-emerald-500' : 'text-red-500');
            modelRefreshStatus.classList.remove('hidden');
        }

        function updateModelOptions(preserveValue) {
            const provider = providerSelect.value;
            const models = getModelsFor(provider);
            const prev = preserveValue !== undefined ? preserveValue : modelSelect.value;
            modelSelect.innerHTML = '';
            models.forEach(m => {
                const opt = document.createElement('option');
                opt.value = m.value;
                opt.textContent = m.text;
                modelSelect.appendChild(opt);
            });
            if (prev && [...modelSelect.options].some(o => o.value === prev)) modelSelect.value = prev;
            renderModelUpdatedAt(provider);
            toggleCustomModelInput();
        }

        function toggleCustomModelInput() {
            if (modelSelect.value === 'custom') {
                customModelBox.classList.remove('hidden');
                customModelInput.required = true;
            } else {
                customModelBox.classList.add('hidden');
                customModelInput.required = false;
                customModelInput.value = '';
            }
        }

        providerSelect.addEventListener('change', () => updateModelOptions());
        modelSelect.addEventListener('change', toggleCustomModelInput);

        async function loadCachedModels() {
            try {
                const res = await fetch('api_action.php?action=list_models');
                const data = await res.json();
                if (data.status === 'success' && data.models) {
                    cachedModels = data.models;
                    if (data.updated_at_by_provider) cachedUpdatedAt = data.updated_at_by_provider;
                }
            } catch(e) {}
            updateModelOptions();
        }

        if (btnRefreshModels) {
            btnRefreshModels.addEventListener('click', async () => {
                const provider = providerSelect.value;
                const customKey = document.getElementById('custom_api_key') ? document.getElementById('custom_api_key').value.trim() : '';
                const prevVal = modelSelect.value;
                btnRefreshModels.disabled = true;
                const origHtml = btnRefreshModels.innerHTML;
                btnRefreshModels.innerHTML = '<span class="animate-spin inline-block w-3 h-3 border border-violet-600 border-t-transparent rounded-full"></span> Memuat...';
                if (modelRefreshStatus) { modelRefreshStatus.classList.add('hidden'); modelRefreshStatus.textContent = ''; }
                try {
                    const fd = new FormData();
                    fd.append('provider', provider);
                    if (customKey) fd.append('custom_key', customKey);
                    const res = await fetch('api_action.php?action=refresh_models', { method: 'POST', body: fd });
                    const text = await res.text();
                    let data;
                    try { data = JSON.parse(text); } catch(pe) { throw new Error('Server tidak balas JSON (' + res.status + '): ' + text.slice(0, 400)); }
                    if (!res.ok && data.status !== 'error') throw new Error('HTTP ' + res.status + ': ' + text.slice(0,400));
                    if (data.status === 'success') {
                        if (!cachedModels) cachedModels = {};
                        cachedModels[provider] = data.models;
                        cachedUpdatedAt[provider] = data.updated_at;
                        updateModelOptions(prevVal);
                        setModelRefreshStatus('Berhasil (' + data.count + ' model)', true);
                        showClientSuccess('Model ' + provider + ' diperbarui: ' + data.count + ' model tersedia.');
                    } else {
                        if (data.cached_models) {
                            if (!cachedModels) cachedModels = {};
                            cachedModels[provider] = data.cached_models;
                            updateModelOptions(prevVal);
                        }
                        if (data.fallback_models && (!cachedModels || !cachedModels[provider])) {
                            if (!cachedModels) cachedModels = {};
                            cachedModels[provider] = data.fallback_models;
                            updateModelOptions(prevVal);
                        }
                        setModelRefreshStatus(data.message || 'Gagal', false);
                        showClientError(data.message || 'Gagal memperbarui model.');
                    }
                } catch(e) {
                    setModelRefreshStatus(e.message.slice(0, 120), false);
                    showClientError(e.message || 'Gagal terhubung ke server saat refresh model.');
                    console.error('[refresh_models]', e);
                } finally {
                    btnRefreshModels.disabled = false;
                    btnRefreshModels.innerHTML = origHtml;
                }
            });
        }

        loadCachedModels();

        const vaultListEl = document.getElementById('vault-list');
        const vaultCountEl = document.getElementById('vault-count');
        const vaultNewKeyEl = document.getElementById('vault_new_key');
        const btnVaultAdd = document.getElementById('btn-vault-add');
        const vaultStatusEl = document.getElementById('vault-status');

        function setVaultStatus(msg, ok) {
            if (!vaultStatusEl) return;
            vaultStatusEl.textContent = msg;
            vaultStatusEl.classList.remove('hidden', 'text-emerald-500', 'text-red-500', 'text-slate-400');
            vaultStatusEl.classList.add(ok ? 'text-emerald-500' : 'text-red-500');
            vaultStatusEl.classList.remove('hidden');
        }

        function renderVault(data) {
            if (!vaultListEl) return;
            vaultListEl.innerHTML = '';
            const cfg = data.config_keys || [];
            const vault = data.vault_keys || [];
            const total = cfg.length + vault.length;
            const providerLabel = (data.provider || 'groq').charAt(0).toUpperCase() + (data.provider || 'groq').slice(1);
            if (vaultCountEl) {
                if (total === 0) vaultCountEl.textContent = '0 key · belum ada';
                else vaultCountEl.textContent = total + ' key aktif · ' + cfg.length + ' server + ' + vault.length + ' vault  ● Siap rotasi';
            }
            if (total === 0) {
                vaultListEl.innerHTML = '<div class="text-[8px] text-slate-400 dark:text-slate-600 italic">Belum ada key untuk ' + providerLabel + '. Tambah 1 key Groq (gsk_...) untuk aktifkan rotasi.</div>';
                return;
            }
            if (vault.length === 0) {
                vaultListEl.innerHTML = '<div class="text-[8px] text-emerald-600 dark:text-emerald-400 bg-emerald-50/60 dark:bg-emerald-950/20 border border-emerald-200/40 dark:border-emerald-900/30 rounded-md px-2.5 py-1.5">' + cfg.length + ' key server aktif dari config. Tambah key vault di bawah untuk rotasi tanpa edit file.</div>';
                return;
            }
            vault.forEach((k) => {
                const row = document.createElement('div');
                row.className = 'flex items-center justify-between gap-2 px-2.5 py-1.5 rounded-md bg-white dark:bg-slate-900/40 border border-slate-200 dark:border-slate-800';
                const left = document.createElement('span');
                left.className = 'text-[9px] font-mono text-slate-600 dark:text-slate-300 truncate';
                left.textContent = k.masked;
                const del = document.createElement('button');
                del.type = 'button';
                del.className = 'shrink-0 px-2 py-1 rounded bg-red-500/10 hover:bg-red-500/20 text-red-600 dark:text-red-400 text-[8px] font-bold transition-colors cursor-pointer';
                del.textContent = 'Hapus';
                del.addEventListener('click', async () => {
                    if (!confirm('Hapus key ' + k.masked + ' dari vault?')) return;
                    del.disabled = true; del.textContent = '...';
                    try {
                        const fd = new FormData(); fd.append('provider', providerSelect.value); fd.append('idx', k.idx);
                        const res = await fetch('api_action.php?action=keys_remove', { method: 'POST', body: fd });
                        const d = await res.json();
                        if (d.status === 'success') { setVaultStatus(d.message, true); refreshVault(); }
                        else { setVaultStatus(d.message, false); del.disabled = false; del.textContent = 'Hapus'; }
                    } catch(e) { setVaultStatus('Gagal terhubung', false); del.disabled = false; del.textContent = 'Hapus'; }
                });
                row.append(left, del);
                vaultListEl.appendChild(row);
            });
        }

        async function refreshVault() {
            const provider = providerSelect.value;
            if (vaultStatusEl) { vaultStatusEl.classList.add('hidden'); vaultStatusEl.textContent = ''; }
            try {
                const res = await fetch('api_action.php?action=keys_list&provider=' + encodeURIComponent(provider));
                const data = await res.json();
                if (data.status === 'success') renderVault(data);
            } catch(e) {}
        }

        if (btnVaultAdd) {
            btnVaultAdd.addEventListener('click', async () => {
                const provider = providerSelect.value;
                const key = vaultNewKeyEl ? vaultNewKeyEl.value.trim() : '';
                if (!key) { setVaultStatus('API key kosong.', false); return; }
                if (provider === 'groq' && !/^gsk_[A-Za-z0-9]{20,}$/.test(key)) { setVaultStatus('Format Groq harus gsk_ + 20 char alfanumerik.', false); return; }
                btnVaultAdd.disabled = true; const orig = btnVaultAdd.textContent; btnVaultAdd.textContent = 'Validasi...';
                if (vaultStatusEl) { vaultStatusEl.classList.add('hidden'); vaultStatusEl.textContent = ''; }
                try {
                    const fd = new FormData(); fd.append('provider', provider); fd.append('api_key', key);
                    const res = await fetch('api_action.php?action=keys_add', { method: 'POST', body: fd });
                    const text = await res.text(); let data; try { data = JSON.parse(text); } catch(pe) { throw new Error('Server tidak balas JSON: ' + text.slice(0,300)); }
                    if (data.status === 'success') {
                        setVaultStatus(data.message + ' ' + (data.masked||''), true);
                        if (vaultNewKeyEl) vaultNewKeyEl.value = '';
                        showClientSuccess(data.message);
                        refreshVault();
                    } else {
                        setVaultStatus(data.message || 'Gagal', false);
                        showClientError(data.message || 'Gagal menambah key.');
                    }
                } catch(e) { setVaultStatus(e.message.slice(0,140), false); showClientError(e.message); }
                finally { btnVaultAdd.disabled = false; btnVaultAdd.textContent = orig; }
            });
            if (vaultNewKeyEl) vaultNewKeyEl.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); btnVaultAdd.click(); } });
        }

        providerSelect.addEventListener('change', refreshVault);
        refreshVault();

        // AJAX: Update Cache Sizes dari Server
        function formatCacheSize(bytes, entries) {
            if (entries === 0) return 'Kosong';
            if (bytes < 1024) return bytes + ' B (' + entries + ' entri)';
            return (bytes / 1024).toFixed(2) + ' KB (' + entries + ' entri)';
        }

        function updateCacheSizes() {
            const translateLabel = document.getElementById('translate-cache-size');
            const aiLabel = document.getElementById('ai-cache-size');
            
            fetch('api_action.php?action=get_cache_info')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        translateLabel.textContent = formatCacheSize(data.translate_bytes, data.translate_entries);
                        aiLabel.textContent        = formatCacheSize(data.ai_bytes, data.ai_entries);
                    }
                })
                .catch(err => {
                    translateLabel.textContent = 'Gagal';
                    aiLabel.textContent = 'Gagal';
                });
        }
        
        // Panggil kapasitas cache saat halaman dimuat
        updateCacheSizes();
        
        // AJAX: Tombol Bersihkan Cache
        const btnClearCache = document.getElementById('btn-clear-cache');
        btnClearCache.addEventListener('click', () => {
            if (confirm('Apakah Anda yakin ingin menghapus seluruh cache terjemahan dan AI siber? Tindakan ini tidak dapat dibatalkan.')) {
                btnClearCache.disabled = true;
                btnClearCache.textContent = 'Membersihkan...';
                
                fetch('api_action.php?action=clear_cache')
                    .then(res => res.json())
                    .then(data => {
                        btnClearCache.disabled = false;
                        btnClearCache.innerHTML = 'Bersihkan Cache siber 🧹';
                        if (data.status === 'success') {
                            showClientSuccess(data.message);
                            updateCacheSizes();
                        } else {
                            showClientError('Gagal membersihkan cache.');
                        }
                    })
                    .catch(err => {
                        btnClearCache.disabled = false;
                        btnClearCache.innerHTML = 'Bersihkan Cache siber 🧹';
                        showClientError('Terjadi kesalahan saat menghubungi server.');
                    });
            }
        });
        
        // AJAX: Tombol Uji Koneksi API Key
        const btnTestConnection = document.getElementById('btn-test-connection');
        const connTestStatus = document.getElementById('conn-test-status');
        
        btnTestConnection.addEventListener('click', () => {
            const provider = providerSelect.value;
            const customKey = document.getElementById('custom_api_key').value;
            
            // Ambil model pilihan (bila custom, gunakan input teks)
            const modelVal = modelSelect.value;
            const model = modelVal === 'custom' ? customModelInput.value : modelVal;
            
            btnTestConnection.disabled = true;
            btnTestConnection.textContent = 'Memeriksa...';
            connTestStatus.classList.remove('hidden', 'text-emerald-500', 'text-red-500', 'text-slate-400');
            connTestStatus.classList.add('text-slate-400');
            connTestStatus.textContent = 'Menghubungkan ke API...';
            
            const formData = new FormData();
            formData.append('provider', provider);
            formData.append('custom_key', customKey);
            formData.append('model', model);
            
            fetch('api_action.php?action=test_connection', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                btnTestConnection.disabled = false;
                btnTestConnection.innerHTML = 'Uji Koneksi API ⚡';
                
                if (data.status === 'success') {
                    connTestStatus.classList.remove('text-slate-400', 'text-red-500');
                    connTestStatus.classList.add('text-emerald-500');
                    connTestStatus.textContent = 'Koneksi Sukses!';
                    showClientSuccess(data.message);
                } else {
                    connTestStatus.classList.remove('text-slate-400', 'text-emerald-500');
                    connTestStatus.classList.add('text-red-500');
                    connTestStatus.textContent = 'Koneksi Gagal!';
                    showClientError(data.message);
                }
            })
            .catch(err => {
                btnTestConnection.disabled = false;
                btnTestConnection.innerHTML = 'Uji Koneksi API ⚡';
                connTestStatus.classList.remove('text-slate-400', 'text-emerald-500');
                connTestStatus.classList.add('text-red-500');
                connTestStatus.textContent = 'Gagal!';
                showClientError('Terjadi kesalahan saat memverifikasi API Key.');
            });
        });
    </script>
</body>
</html>
