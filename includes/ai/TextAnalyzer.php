<?php
declare(strict_types=1);

/**
 * Lightweight, dependency-free NLP used before any AI call:
 *  - language detection (English / Malay / Chinese)
 *  - rule-based intent classification
 *  - lexicon-based sentiment detection
 *  - keyword extraction with multilingual synonym expansion (for knowledge retrieval)
 *
 * Running these locally means intent/sentiment still work when every AI provider is offline.
 */
final class TextAnalyzer
{
    public const INTENTS = [
        'greeting'          => 'Greeting',
        'product_info'      => 'Product Information',
        'pricing'           => 'Pricing',
        'payment'           => 'Payment',
        'opening_hours'     => 'Opening Hours',
        'location'          => 'Location',
        'account_support'   => 'Account Support',
        'technical_support' => 'Technical Support',
        'refund'            => 'Refund',
        'cancellation'      => 'Cancellation',
        'complaint'         => 'Complaint',
        'order_status'      => 'Order/Service Status',
        'human_support'     => 'Human Support',
        'general'           => 'General Question',
    ];

    public const SENTIMENTS = ['positive', 'neutral', 'negative', 'angry'];

    /** Intent => trigger phrases (EN / BM / 中文). Order matters for ties: earlier wins. */
    private const INTENT_TERMS = [
        'human_support' => ['human', 'real person', 'live agent', 'agent', 'representative', 'speak to someone', 'talk to someone',
            'customer service officer', 'manusia', 'ejen', 'wakil', 'orang sebenar', 'bercakap dengan staf', '人工', '真人', '客服人员', '转人工', '人工客服'],
        'complaint' => ['complain', 'complaint', 'unacceptable', 'disappointed', 'worst', 'terrible', 'ridiculous', 'fed up',
            'aduan', 'kecewa', 'teruk', 'tak puas hati', '投诉', '差劲', '失望', '太差'],
        'refund' => ['refund', 'money back', 'reimburse', 'charged twice', 'double charged', 'duplicate payment', 'overcharged',
            'bayaran balik', 'pulangan wang', 'caj dua kali', '退款', '退钱', '多扣'],
        'cancellation' => ['cancel', 'cancellation', 'terminate', 'termination', 'unsubscribe', 'end my contract', 'stop my service',
            'batal', 'tamatkan', 'berhenti langganan', '取消', '退订', '终止', '解约'],
        'technical_support' => ['not working', 'slow', 'error', 'disconnect', 'disconnecting', 'no internet', 'wifi', 'wi-fi', 'router',
            'red light', 'troubleshoot', 'connection', 'lag', 'buffering', 'pairing', 'cannot connect', "can't connect", 'offline',
            'lambat', 'rosak', 'tak boleh', 'tidak boleh', 'sambungan', 'terputus', 'penghala', 'tiada internet',
            '网速', '断线', '无法', '故障', '路由器', '连不上', '没有网络', '很慢'],
        'account_support' => ['password', 'login', 'log in', 'sign in', 'account', 'username', 'two-step', '2fa', 'verification code', 'locked out',
            'kata laluan', 'akaun', 'log masuk', 'kata kunci', '密码', '账户', '账号', '登录', '验证码'],
        'pricing' => ['price', 'pricing', 'cost', 'how much', 'fee', 'fees', 'plan', 'plans', 'package', 'rm', 'cheap', 'expensive',
            'harga', 'berapa', 'kos', 'pakej', 'pelan', 'yuran', '价格', '多少钱', '费用', '套餐', '收费'],
        'payment' => ['pay', 'payment', 'bill', 'invoice', 'card', 'fpx', 'auto-debit', 'autodebit', 'duitnow', 'due date', 'late fee',
            'bayar', 'bayaran', 'bil', 'invois', '付款', '支付', '账单', '发票', '缴费'],
        'opening_hours' => ['opening hours', 'business hours', 'operating hours', 'working hours', 'support hours', 'open on', 'what time', 'are you open', 'closed on',
            'waktu operasi', 'waktu pejabat', 'pukul berapa', 'dibuka', 'buka', 'tutup', '营业时间', '几点', '开门', '上班时间', '工作时间'],
        'location' => ['where are you', 'address', 'location', 'office', 'branch', 'located', 'visit you',
            'alamat', 'lokasi', 'pejabat', 'cawangan', '地址', '在哪', '位置', '办公室'],
        'order_status' => ['order status', 'installation', 'appointment', 'track', 'delivery', 'technician', 'when will', 'relocation', 'my order',
            'pesanan', 'pemasangan', 'juruteknik', 'temujanji', '订单', '安装', '进度', '配送', '师傅'],
        'product_info' => ['what is', 'features', 'product', 'service', 'novafibre', 'novacloud', 'novahome', 'device', 'hub', 'storage', 'warranty', 'mesh',
            'produk', 'perkhidmatan', 'ciri', 'jaminan', 'storan', '产品', '服务', '功能', '保修', '存储'],
        'greeting' => ['hello', 'hi', 'hey', 'good morning', 'good afternoon', 'good evening', 'thanks', 'thank you', 'bye', 'goodbye',
            'helo', 'hai', 'selamat pagi', 'selamat petang', 'apa khabar', 'terima kasih', '你好', '您好', '嗨', '早上好', '谢谢', '再见'],
    ];

    private const POSITIVE = ['thank', 'thanks', 'great', 'awesome', 'love', 'helpful', 'excellent', 'good', 'perfect', 'appreciate',
        'nice', 'amazing', 'happy', 'solved', 'works now', 'terima kasih', 'bagus', 'hebat', 'mantap', 'gembira', 'membantu',
        '谢谢', '很好', '太好了', '满意', '棒', '感谢', '解决了'];

    private const NEGATIVE = ['not working', 'problem', 'issue', 'slow', 'bad', 'poor', 'disappointed', 'annoyed', 'frustrated',
        'frustrating', 'unhappy', 'broken', 'still', 'again', 'not happy', 'not helpful', 'useless', 'waiting', 'nobody',
        'teruk', 'lambat', 'masalah', 'kecewa', 'rosak', 'tak puas hati', 'geram',
        '不满', '问题', '慢', '失望', '糟糕', '不行', '还是'];

    private const ANGRY = ['ridiculous', 'worst', 'scam', 'angry', 'furious', 'terrible', 'unacceptable', 'rubbish', 'stupid',
        'fed up', 'hate', 'sue', 'lawyer', 'pathetic', 'nonsense', 'disgusting', 'cheat',
        'bodoh', 'marah', 'tipu', 'menyampah', 'bengang', 'sampah',
        '生气', '垃圾', '骗子', '愤怒', '太差', '气死', '离谱', '投诉你们'];

    private const STOPWORDS = ['the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'to', 'of', 'and', 'or', 'in', 'on', 'for', 'with',
        'my', 'me', 'i', 'you', 'your', 'it', 'its', 'this', 'that', 'do', 'does', 'did', 'can', 'could', 'would', 'should',
        'how', 'what', 'when', 'where', 'why', 'which', 'who', 'please', 'pls', 'hi', 'hello', 'there', 'have', 'has', 'had',
        'will', 'about', 'from', 'at', 'by', 'as', 'if', 'but', 'not', 'so', 'we', 'our', 'us', 'they', 'them', 'am', 'get',
        'want', 'need', 'know', 'tell', 'any', 'some', 'there', 'than', 'then', 'also', 'just', 'like', 'thanks', 'thank',
        'saya', 'anda', 'awak', 'kami', 'kita', 'yang', 'dan', 'atau', 'untuk', 'dengan', 'ini', 'itu', 'ada', 'apa',
        'bagaimana', 'macam', 'mana', 'boleh', 'tak', 'tidak', 'ke', 'di', 'dari', 'pada', 'adalah', 'nak', 'mahu', 'ingin',
        'tolong', 'sila', 'berapa', 'bila', 'kenapa', 'lagi', 'sudah', 'dah', 'akan', 'juga', 'hai', 'helo'];

    /** Malay/Chinese terms mapped to the English vocabulary used in the knowledge base. */
    private const SYNONYMS = [
        'kata laluan' => 'password', 'kata kunci' => 'password', 'akaun' => 'account', 'log masuk' => 'login',
        'bayaran balik' => 'refund', 'pulangan wang' => 'refund', 'bayaran' => 'payment', 'bayar' => 'payment', 'bil' => 'bill',
        'harga' => 'price', 'pelan' => 'plan', 'pakej' => 'plan', 'batal' => 'cancel', 'tamatkan' => 'cancel',
        'lambat' => 'slow', 'sambungan' => 'connection', 'terputus' => 'disconnect', 'penghala' => 'router',
        'pemasangan' => 'installation', 'juruteknik' => 'technician', 'waktu operasi' => 'hours', 'buka' => 'hours',
        'alamat' => 'address', 'peranti' => 'device', 'storan' => 'storage', 'jaminan' => 'warranty', 'waranti' => 'warranty',
        'langganan' => 'subscription', 'naik taraf' => 'upgrade', 'pindah' => 'relocation', 'rumah baru' => 'relocation',
        'lampu merah' => 'red light', 'fail' => 'files', 'padam' => 'deleted', 'pulih' => 'restore', 'kontrak' => 'contract',
        '密码' => 'password', '账户' => 'account', '账号' => 'account', '登录' => 'login', '退款' => 'refund', '付款' => 'payment',
        '支付' => 'payment', '账单' => 'bill', '价格' => 'price', '多少钱' => 'price', '套餐' => 'plan', '取消' => 'cancel',
        '网速' => 'speed', '慢' => 'slow', '断线' => 'disconnect', '路由器' => 'router', '红灯' => 'red light', '安装' => 'installation',
        '营业时间' => 'hours', '几点' => 'hours', '地址' => 'address', '设备' => 'device', '存储' => 'storage', '云盘' => 'cloud',
        '保修' => 'warranty', '订阅' => 'subscription', '升级' => 'upgrade', '搬家' => 'relocation', '发票' => 'invoice',
        '重置' => 'reset', '忘记' => 'forgot', '智能家居' => 'smart home', '光纤' => 'fibre', '合约' => 'contract', '删除' => 'deleted',
        '恢复' => 'restore', '自动扣款' => 'auto-debit', '两步验证' => 'two-step',
        'fiber' => 'fibre', 'wifi' => 'wi-fi', 'internet' => 'internet', 'autopay' => 'auto-debit',
    ];

    private const MALAY_MARKERS = ['saya', 'anda', 'boleh', 'tidak', 'tak', 'bagaimana', 'macam', 'mana', 'apa', 'untuk', 'dengan',
        'yang', 'berapa', 'bila', 'kenapa', 'nak', 'mahu', 'tolong', 'sila', 'terima', 'kasih', 'harga', 'akaun', 'bayar',
        'sudah', 'dah', 'belum', 'ini', 'itu', 'ke', 'ada', 'kami', 'pukul', 'hari', 'lagi', 'perlu', 'dibuka', 'khabar'];

    private const ENGLISH_MARKERS = ['the', 'is', 'are', 'how', 'what', 'my', 'can', 'you', 'please', 'why', 'do', 'does', 'to',
        'i', 'have', 'when', 'where', 'need', 'want', 'it', 'not', 'with', 'for', 'this', 'thanks', 'thank'];

    public static function analyze(string $text, string $fallbackLanguage = 'en'): array
    {
        return [
            'language'  => self::detectLanguage($text, $fallbackLanguage),
            'intent'    => self::detectIntent($text),
            'sentiment' => self::detectSentiment($text),
            'keywords'  => self::extractKeywords($text),
        ];
    }

    public static function detectLanguage(string $text, string $fallback = 'en'): string
    {
        if (preg_match('/\p{Han}/u', $text)) {
            return 'zh';
        }
        $words = self::words($text);
        if (!$words) {
            return $fallback;
        }
        $malay = count(array_intersect($words, self::MALAY_MARKERS));
        $english = count(array_intersect($words, self::ENGLISH_MARKERS));
        if ($malay > $english) {
            return 'ms';
        }
        if ($english > 0) {
            return 'en';
        }
        return $fallback;
    }

    public static function detectIntent(string $text): string
    {
        $normalized = mb_strtolower($text);
        $scores = [];
        foreach (self::INTENT_TERMS as $intent => $terms) {
            $score = 0;
            foreach ($terms as $term) {
                if (self::containsTerm($normalized, $term)) {
                    $score += str_contains($term, ' ') || mb_strlen($term) > 6 ? 2 : 1;
                }
            }
            if ($score > 0) {
                $scores[$intent] = $score;
            }
        }
        if (!$scores) {
            return 'general';
        }
        // Greetings only win when the message is short and nothing more specific matched
        if (count($scores) > 1) {
            unset($scores['greeting']);
        } elseif (isset($scores['greeting']) && count(self::words($text)) > 8 && !preg_match('/\p{Han}/u', $text)) {
            return 'general';
        }
        arsort($scores); // stable in PHP 8: ties keep the INTENT_TERMS priority order
        return (string) array_key_first($scores);
    }

    public static function detectSentiment(string $text): string
    {
        $normalized = mb_strtolower($text);
        $angry = 0;
        $negative = 0;
        $positive = 0;

        foreach (self::ANGRY as $term) {
            if (self::containsTerm($normalized, $term)) {
                $angry += 2;
            }
        }
        foreach (self::NEGATIVE as $term) {
            if (self::containsTerm($normalized, $term)) {
                $negative++;
                // Remove so "not helpful" does not also count as positive "helpful"
                $normalized = str_replace($term, ' ', $normalized);
            }
        }
        foreach (self::POSITIVE as $term) {
            if (self::containsTerm($normalized, $term)) {
                $positive++;
            }
        }
        // Shouting signals: several exclamation marks or words in CAPITALS
        if (preg_match('/!{2,}|！{2,}/u', $text)) {
            $angry++;
        }
        if (preg_match_all('/\b[A-Z]{3,}\b/', $text) >= 2) {
            $angry++;
        }

        return match (true) {
            $angry >= 3, ($angry >= 2 && $negative >= 1) => 'angry',
            ($negative + $angry) > $positive              => 'negative',
            $positive > 0                                 => 'positive',
            default                                       => 'neutral',
        };
    }

    /**
     * Returns search keywords: meaningful words + English equivalents of Malay/Chinese terms.
     */
    public static function extractKeywords(string $text, int $max = 10): array
    {
        $normalized = mb_strtolower($text);
        $keywords = [];

        foreach (self::SYNONYMS as $term => $english) {
            if (self::containsTerm($normalized, $term)) {
                foreach (explode(' ', $english) as $word) {
                    $keywords[$word] = true;
                }
            }
        }
        foreach (self::words($text) as $word) {
            // Chinese has no spaces, so whole phrases are useless as keywords; synonyms above cover them
            if (mb_strlen($word) < 3 || in_array($word, self::STOPWORDS, true) || is_numeric($word) || preg_match('/\p{Han}/u', $word)) {
                continue;
            }
            $keywords[self::stem($word)] = true;
        }
        return array_slice(array_keys($keywords), 0, $max);
    }

    /** Very small English stemmer so "payments"/"payment" and "cancelled"/"cancel" match. */
    public static function stem(string $word): string
    {
        if (mb_strlen($word) <= 4) {
            return $word;
        }
        foreach (['lled' => 'l', 'ied' => 'y', 'ies' => 'y', 'ing' => '', 'ed' => '', 'es' => '', 's' => ''] as $suffix => $replacement) {
            if (str_ends_with($word, $suffix) && mb_strlen($word) - strlen($suffix) >= 4) {
                return substr($word, 0, -strlen($suffix)) . $replacement;
            }
        }
        return $word;
    }

    private static function words(string $text): array
    {
        $clean = preg_replace('/[^\p{L}\p{N}\s\'-]/u', ' ', mb_strtolower($text)) ?? '';
        return array_values(array_filter(preg_split('/\s+/u', $clean) ?: [], static fn ($w) => $w !== ''));
    }

    /** Whole-word match for Latin text; substring match for Chinese characters. */
    private static function containsTerm(string $haystack, string $term): bool
    {
        if (preg_match('/\p{Han}/u', $term)) {
            return mb_strpos($haystack, $term) !== false;
        }
        return (bool) preg_match('/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/u', $haystack);
    }
}
