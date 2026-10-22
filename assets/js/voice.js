/**
 * Browser voice features (Web Speech API).
 *  - Voice input:  SpeechRecognition (Chrome, Edge, Safari; not Firefox)
 *  - Voice output: SpeechSynthesis   (most modern browsers; available voices vary by OS)
 * Everything degrades gracefully when the browser lacks support.
 */
window.NSVoice = (function () {
    'use strict';

    const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition || null;
    const synth = 'speechSynthesis' in window ? window.speechSynthesis : null;
    const SPEECH_LANG = { en: 'en-US', ms: 'ms-MY', zh: 'zh-CN' };

    const prefs = {
        rate: parseFloat(localStorage.getItem('ns.voice.rate') || '1') || 1,
        voiceURI: localStorage.getItem('ns.voice.uri') || '',
        autoSpeak: localStorage.getItem('ns.voice.auto') === '1',
    };

    let recognizer = null;
    let listening = false;
    let voices = [];

    function canListen() { return !!Recognition; }
    function canSpeak() { return !!synth && typeof window.SpeechSynthesisUtterance !== 'undefined'; }
    function isListening() { return listening; }

    /**
     * Starts dictation. Callbacks: onText(currentText), onEnd(finalText), onError(code)
     */
    function listen(lang, callbacks) {
        if (!Recognition) return false;
        if (listening) { stopListening(); return false; }
        recognizer = new Recognition();
        recognizer.lang = lang;
        recognizer.interimResults = true;
        recognizer.continuous = false;
        recognizer.maxAlternatives = 1;
        let finalText = '';
        recognizer.onresult = (event) => {
            let interim = '';
            for (let i = event.resultIndex; i < event.results.length; i++) {
                const transcript = event.results[i][0].transcript;
                if (event.results[i].isFinal) finalText += transcript; else interim += transcript;
            }
            callbacks.onText && callbacks.onText((finalText + interim).trim());
        };
        recognizer.onerror = (event) => { callbacks.onError && callbacks.onError(event.error); };
        recognizer.onend = () => { listening = false; callbacks.onEnd && callbacks.onEnd(finalText.trim()); };
        try {
            recognizer.start();
            listening = true;
        } catch (e) {
            listening = false;
            callbacks.onError && callbacks.onError('start-failed');
            return false;
        }
        return true;
    }

    function stopListening() {
        if (recognizer) { try { recognizer.stop(); } catch (e) { /* already stopped */ } }
    }

    function loadVoices() {
        voices = synth ? synth.getVoices() : [];
        return voices;
    }

    function pickVoice(lang) {
        if (!voices.length) loadVoices();
        if (prefs.voiceURI) {
            const chosen = voices.find((v) => v.voiceURI === prefs.voiceURI);
            if (chosen && chosen.lang.toLowerCase().startsWith(lang.slice(0, 2).toLowerCase())) return chosen;
        }
        const base = lang.slice(0, 2).toLowerCase();
        return voices.find((v) => v.lang.toLowerCase() === lang.toLowerCase())
            || voices.find((v) => v.lang.toLowerCase().startsWith(base))
            || null;
    }

    /** Removes markdown symbols and URLs so they are not read aloud. */
    function cleanForSpeech(text) {
        return String(text)
            .replace(/\*\*/g, '')
            .replace(/^#+\s*/gm, '')
            .replace(/^\s*[-•*]\s+/gm, '')
            .replace(/https?:\/\/\S+/g, '')
            .trim();
    }

    function speak(text, langCode, onEnd) {
        if (!canSpeak()) return false;
        synth.cancel();
        const lang = SPEECH_LANG[langCode] || langCode || 'en-US';
        const utterance = new SpeechSynthesisUtterance(cleanForSpeech(text));
        utterance.lang = lang;
        utterance.rate = prefs.rate;
        const voice = pickVoice(lang);
        if (voice) { utterance.voice = voice; utterance.lang = voice.lang; }
        utterance.onend = () => onEnd && onEnd();
        utterance.onerror = () => onEnd && onEnd();
        synth.speak(utterance);
        return true;
    }

    function stopSpeaking() { if (synth) synth.cancel(); }

    function setRate(rate) { prefs.rate = rate; localStorage.setItem('ns.voice.rate', String(rate)); }
    function setVoice(uri) { prefs.voiceURI = uri; localStorage.setItem('ns.voice.uri', uri); }
    function setAutoSpeak(on) { prefs.autoSpeak = !!on; localStorage.setItem('ns.voice.auto', on ? '1' : '0'); }

    /** Fills a <select> with the voices installed on this device (current language first). */
    function populateSelect(select, uiLang, defaultLabel) {
        if (!select) return;
        const fill = () => {
            const list = loadVoices();
            const base = (SPEECH_LANG[uiLang] || 'en').slice(0, 2);
            const sorted = list.slice().sort((a, b) => {
                const am = a.lang.toLowerCase().startsWith(base) ? 0 : 1;
                const bm = b.lang.toLowerCase().startsWith(base) ? 0 : 1;
                return am - bm || a.lang.localeCompare(b.lang) || a.name.localeCompare(b.name);
            });
            select.innerHTML = '';
            select.add(new Option(defaultLabel, ''));
            sorted.forEach((v) => select.add(new Option(v.name + ' (' + v.lang + ')', v.voiceURI, false, v.voiceURI === prefs.voiceURI)));
            select.disabled = !sorted.length;
        };
        fill();
        if (synth && 'onvoiceschanged' in synth) synth.addEventListener('voiceschanged', fill);
    }

    return {
        SPEECH_LANG, prefs, canListen, canSpeak, isListening, listen, stopListening,
        speak, stopSpeaking, setRate, setVoice, setAutoSpeak, populateSelect,
    };
})();
