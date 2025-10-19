<?php

declare(strict_types=1);

namespace App\Text;

use EnglishLexicon;

require_once __DIR__ . '/../../EnglishLexicon.php';

/**
 * Utility helpers for transforming unstructured text into structured signals.
 */
final class TextRefiner
{
    private EnglishLexicon $lexicon;

    /** @var array<string, bool> */
    private static array $stopwords = [
        'a' => true,
        'an' => true,
        'and' => true,
        'are' => true,
        'as' => true,
        'at' => true,
        'away' => true,
        'be' => true,
        'but' => true,
        'by' => true,
        'for' => true,
        'from' => true,
        'has' => true,
        'have' => true,
        'into' => true,
        'in' => true,
        'is' => true,
        'it' => true,
        'its' => true,
        'off' => true,
        'of' => true,
        'on' => true,
        'onto' => true,
        'or' => true,
        'that' => true,
        'the' => true,
        'their' => true,
        'this' => true,
        'to' => true,
        'was' => true,
        'were' => true,
        'with' => true,
        'your' => true,
    ];

    /** @var array<string, bool> */
    private static array $pronouns = [
        'i' => true,
        'you' => true,
        'he' => true,
        'she' => true,
        'it' => true,
        'we' => true,
        'they' => true,
        'me' => true,
        'him' => true,
        'her' => true,
        'us' => true,
        'them' => true,
        'this' => true,
        'that' => true,
        'these' => true,
        'those' => true,
        'someone' => true,
        'somebody' => true,
        'anyone' => true,
        'anybody' => true,
        'everyone' => true,
        'everybody' => true,
        'who' => true,
        'which' => true,
        'there' => true,
        'people' => true,
        'person' => true,
        'team' => true,
        'company' => true,
        'government' => true,
        'market' => true,
    ];

    /** @var array<string, bool> */
    private static array $determiners = [
        'a' => true,
        'an' => true,
        'the' => true,
        'this' => true,
        'that' => true,
        'these' => true,
        'those' => true,
        'my' => true,
        'your' => true,
        'his' => true,
        'her' => true,
        'its' => true,
        'our' => true,
        'their' => true,
        'each' => true,
        'every' => true,
        'any' => true,
        'some' => true,
    ];

    /** @var array<string, bool> */
    private static array $adjectiveIntensifiers = [
        'very' => true,
        'more' => true,
        'most' => true,
        'quite' => true,
        'rather' => true,
        'fairly' => true,
        'extremely' => true,
        'highly' => true,
        'particularly' => true,
        'especially' => true,
        'incredibly' => true,
        'remarkably' => true,
        'significantly' => true,
        'somewhat' => true,
        'slightly' => true,
        'too' => true,
        'so' => true,
    ];

    /** @var array<int, string> */
    private static array $adjectiveSuffixes = [
        'ous',
        'ive',
        'ful',
        'less',
        'able',
        'ible',
        'al',
        'ial',
        'ic',
        'ical',
        'ish',
        'ary',
        'ory',
        'ant',
        'ent',
        'ate',
        'ing',
        'ed',
        'y',
    ];

    /** @var array<string, bool> */
    private static array $clausePronouns = [
        'i' => true,
        'you' => true,
        'he' => true,
        'she' => true,
        'it' => true,
        'we' => true,
        'they' => true,
        'this' => true,
        'these' => true,
        'those' => true,
        'there' => true,
    ];

    /** @var array<string, bool> */
    private static array $nonSubjectVocabulary = [
        'above' => true,
        'across' => true,
        'after' => true,
        'along' => true,
        'amid' => true,
        'amidst' => true,
        'among' => true,
        'around' => true,
        'behind' => true,
        'below' => true,
        'beneath' => true,
        'beside' => true,
        'besides' => true,
        'between' => true,
        'beyond' => true,
        'down' => true,
        'inside' => true,
        'near' => true,
        'outside' => true,
        'over' => true,
        'through' => true,
        'throughout' => true,
        'toward' => true,
        'towards' => true,
        'under' => true,
        'underneath' => true,
        'up' => true,
        'upon' => true,
        'within' => true,
        'without' => true,
    ];

    /** @var array<string, bool> */
    private static array $verbForms = [
        'am' => true,
        'are' => true,
        'be' => true,
        'been' => true,
        'being' => true,
        'is' => true,
        'was' => true,
        'were' => true,
        'do' => true,
        'does' => true,
        'did' => true,
        'done' => true,
        'can' => true,
        'could' => true,
        'will' => true,
        'would' => true,
        'shall' => true,
        'should' => true,
        'may' => true,
        'might' => true,
        'must' => true,
        'need' => true,
        'needs' => true,
        'needed' => true,
        'seem' => true,
        'seems' => true,
        'seemed' => true,
        'seeming' => true,
        'go' => true,
        'goes' => true,
        'going' => true,
        'went' => true,
        'gone' => true,
        'make' => true,
        'makes' => true,
        'made' => true,
        'take' => true,
        'takes' => true,
        'taking' => true,
        'took' => true,
        'taken' => true,
        'come' => true,
        'comes' => true,
        'coming' => true,
        'came' => true,
        'run' => true,
        'runs' => true,
        'running' => true,
        'ran' => true,
        'sang' => true,
        'sing' => true,
        'sings' => true,
        'sung' => true,
        'say' => true,
        'says' => true,
        'saying' => true,
        'said' => true,
        'see' => true,
        'sees' => true,
        'seeing' => true,
        'saw' => true,
        'seen' => true,
        'know' => true,
        'knows' => true,
        'knowing' => true,
        'knew' => true,
        'known' => true,
        'think' => true,
        'thinks' => true,
        'thinking' => true,
        'thought' => true,
        'get' => true,
        'gets' => true,
        'getting' => true,
        'got' => true,
        'give' => true,
        'gives' => true,
        'giving' => true,
        'gave' => true,
        'given' => true,
        'work' => true,
        'works' => true,
        'working' => true,
        'worked' => true,
        'call' => true,
        'calls' => true,
        'calling' => true,
        'called' => true,
        'feel' => true,
        'feels' => true,
        'feeling' => true,
        'felt' => true,
        'try' => true,
        'tries' => true,
        'trying' => true,
        'tried' => true,
        'leave' => true,
        'leaves' => true,
        'leaving' => true,
        'left' => true,
        'bring' => true,
        'brings' => true,
        'bringing' => true,
        'brought' => true,
        'begin' => true,
        'begins' => true,
        'beginning' => true,
        'began' => true,
        'begun' => true,
        'keep' => true,
        'keeps' => true,
        'keeping' => true,
        'kept' => true,
        'hold' => true,
        'holds' => true,
        'holding' => true,
        'held' => true,
        'hear' => true,
        'hears' => true,
        'hearing' => true,
        'heard' => true,
        'play' => true,
        'plays' => true,
        'playing' => true,
        'played' => true,
        'move' => true,
        'moves' => true,
        'moving' => true,
        'moved' => true,
        'live' => true,
        'lives' => true,
        'living' => true,
        'lived' => true,
        'believe' => true,
        'believes' => true,
        'believing' => true,
        'believed' => true,
        'happen' => true,
        'happens' => true,
        'happening' => true,
        'happened' => true,
        'provide' => true,
        'provides' => true,
        'providing' => true,
        'provided' => true,
        'create' => true,
        'creates' => true,
        'creating' => true,
        'created' => true,
        'support' => true,
        'supports' => true,
        'supporting' => true,
        'supported' => true,
        'drive' => true,
        'drives' => true,
        'driving' => true,
        'drove' => true,
        'driven' => true,
        'lead' => true,
        'leads' => true,
        'leading' => true,
        'led' => true,
        'grow' => true,
        'grows' => true,
        'growing' => true,
        'grew' => true,
        'grown' => true,
        'improve' => true,
        'improves' => true,
        'improving' => true,
        'improved' => true,
        'deliver' => true,
        'delivers' => true,
        'delivering' => true,
        'delivered' => true,
        'launch' => true,
        'launches' => true,
        'launching' => true,
        'launched' => true,
        'report' => true,
        'reports' => true,
        'reporting' => true,
        'reported' => true,
        'announce' => true,
        'announces' => true,
        'announcing' => true,
        'announced' => true,
        'plan' => true,
        'plans' => true,
        'planning' => true,
        'planned' => true,
        'love' => true,
        'loves' => true,
        'loving' => true,
        'loved' => true,
        'win' => true,
        'wins' => true,
        'winning' => true,
        'won' => true,
        'walk' => true,
        'walks' => true,
        'walking' => true,
        'walked' => true,
        'talk' => true,
        'talks' => true,
        'talking' => true,
        'talked' => true,
        'read' => true,
        'reads' => true,
        'reading' => true,
        'wrote' => true,
        'write' => true,
        'writes' => true,
        'writing' => true,
        'eat' => true,
        'eats' => true,
        'eating' => true,
        'ate' => true,
        'eaten' => true,
        'drink' => true,
        'drinks' => true,
        'drinking' => true,
        'drank' => true,
        'drunk' => true,
        'build' => true,
        'builds' => true,
        'building' => true,
        'built' => true,
        'study' => true,
        'studies' => true,
        'studying' => true,
        'studied' => true,
        'learn' => true,
        'learns' => true,
        'learning' => true,
        'learned' => true,
        'learnt' => true,
        'monitor' => true,
        'monitors' => true,
        'monitoring' => true,
        'monitored' => true,
        'analyze' => true,
        'analyzes' => true,
        'analyzing' => true,
        'analysed' => true,
        'analyse' => true,
        'analyses' => true,
        'analysing' => true,
    ];

    /** @var array<string, bool> */
    private static array $boilerplateVocabulary = [
        'accept' => true,
        'accessibility' => true,
        'account' => true,
        'additional' => true,
        'advertisement' => true,
        'advertisements' => true,
        'bitesize' => true,
        'best' => true,
        'bookmark' => true,
        'cookies' => true,
        'contact' => true,
        'copyright' => true,
        'download' => true,
        'elsewhere' => true,
        'email' => true,
        'emails' => true,
        'guidance' => true,
        'help' => true,
        'homepage' => true,
        'iplayer' => true,
        'login' => true,
        'menu' => true,
        'mobile' => true,
        'more' => true,
        'newsletter' => true,
        'notifications' => true,
        'parental' => true,
        'policy' => true,
        'privacy' => true,
        'register' => true,
        'reject' => true,
        'services' => true,
        'sign' => true,
        'skip' => true,
        'smart' => true,
        'sounds' => true,
        'speakers' => true,
        'terms' => true,
        'use' => true,
        'watchlistadd' => true,
        'viewing' => true,
        'weather' => true,
    ];

    /** @var array<string, bool> */
    private static array $boilerplatePhrases = [
        'accept additional cookies' => true,
        'advertisement' => true,
        'advertisements' => true,
        'reject additional cookies' => true,
        'let me choose' => true,
        'bbc homepage' => true,
        'skip to content' => true,
        'accessibility help' => true,
        'sign in' => true,
        'search bbc' => true,
        'search the web' => true,
        'search query' => true,
        'top stories' => true,
        'view comments' => true,
        'related topics' => true,
        'related articles' => true,
        'scroll to previous item' => true,
        'scroll to next item' => true,
        'live page' => true,
        'watch live' => true,
        'return to homepage' => true,
        'back to homepage' => true,
        'bbc news services' => true,
        'on your mobile' => true,
        'on smart speakers' => true,
        'get news alerts' => true,
        'contact bbc news' => true,
        'terms of use' => true,
        'privacy policy' => true,
        'parental guidance' => true,
        'contact the bbc' => true,
        'make an editorial complaint' => true,
        'bbc emails for you' => true,
        'copyright © 2025 bbc' => true,
        'copyright © 2024 bbc' => true,
        'copyright © 2023 bbc' => true,
    ];

    /** @var array<int, string> */
    private static array $boilerplatePatterns = [
        '/^ad\b/i',
        '/^advertisement\b/i',
        '/^advertorial\b/i',
        '/^related\.{2,}$/i',
        '/^watchlistadd\b/i',
        '/^subscribeadd\b/i',
        '/^published\s+\d{1,2}/i',
        '/^updated\s+\d{1,2}/i',
        '/^media caption/i',
        '/^more to explore/i',
        '/^elsewhere on the bbc/i',
        '/^best of the bbc/i',
        '/^bbc news$/i',
        '/^home$/i',
        '/^news$/i',
        '/^sport$/i',
        '/^weather$/i',
        '/^iplayer$/i',
        '/^sounds$/i',
        '/^bitesize$/i',
    ];

    /** @var array<string, bool> */
    private static array $navigationVocabulary = [
        'about' => true,
        'account' => true,
        'advertisement' => true,
        'advertisements' => true,
        'apps' => true,
        'archive' => true,
        'back' => true,
        'bbc' => true,
        'careers' => true,
        'categories' => true,
        'contact' => true,
        'deals' => true,
        'events' => true,
        'finance' => true,
        'games' => true,
        'home' => true,
        'homepage' => true,
        'latest' => true,
        'live' => true,
        'login' => true,
        'mail' => true,
        'main' => true,
        'market' => true,
        'markets' => true,
        'menu' => true,
        'more' => true,
        'movies' => true,
        'news' => true,
        'privacy' => true,
        'return' => true,
        'search' => true,
        'settings' => true,
        'shop' => true,
        'skip' => true,
        'signin' => true,
        'sign' => true,
        'sports' => true,
        'subscribe' => true,
        'technology' => true,
        'terms' => true,
        'topics' => true,
        'trending' => true,
        'video' => true,
        'weather' => true,
    ];

    /** @var array<string, bool> */
    private static array $categoryVocabulary = [
        'climate' => true,
        'consumer' => true,
        'culture' => true,
        'energy' => true,
        'finance' => true,
        'geopolitics' => true,
        'healthcare' => true,
        'markets' => true,
        'science' => true,
        'sports' => true,
        'technology' => true,
        'transportation' => true,
    ];

    /** @var array<string, bool> */
    private static array $positiveSentimentWords = [
        'advance' => true,
        'amazing' => true,
        'benefit' => true,
        'confident' => true,
        'effective' => true,
        'efficient' => true,
        'excellent' => true,
        'favorable' => true,
        'good' => true,
        'great' => true,
        'growth' => true,
        'improved' => true,
        'innovative' => true,
        'leading' => true,
        'positive' => true,
        'promising' => true,
        'successful' => true,
        'supportive' => true,
        'thriving' => true,
        'transformative' => true,
        'valuable' => true,
    ];

    /** @var array<string, bool> */
    private static array $negativeSentimentWords = [
        'alarming' => true,
        'bad' => true,
        'concern' => true,
        'critical' => true,
        'crisis' => true,
        'decline' => true,
        'difficult' => true,
        'failure' => true,
        'frustrated' => true,
        'issue' => true,
        'loss' => true,
        'negative' => true,
        'problem' => true,
        'risk' => true,
        'setback' => true,
        'shortfall' => true,
        'struggle' => true,
        'trouble' => true,
        'uncertain' => true,
        'weak' => true,
        'worry' => true,
    ];

    /** @var array<string, bool> */
    private static array $speculativeVocabulary = [
        'alleged' => true,
        'apparently' => true,
        'appears' => true,
        'assume' => true,
        'believe' => true,
        'could' => true,
        'estimates' => true,
        'expected' => true,
        'forecast' => true,
        'likely' => true,
        'maybe' => true,
        'might' => true,
        'perhaps' => true,
        'potentially' => true,
        'possibly' => true,
        'presumably' => true,
        'probable' => true,
        'rumor' => true,
        'rumour' => true,
        'seems' => true,
        'suggests' => true,
        'uncertain' => true,
        'unconfirmed' => true,
        'would' => true,
    ];

    /** @var array<string, bool> */
    private static array $assertiveVocabulary = [
        'always' => true,
        'certainly' => true,
        'clearly' => true,
        'definitely' => true,
        'ensured' => true,
        'ensures' => true,
        'guaranteed' => true,
        'must' => true,
        'proves' => true,
        'confirmed' => true,
        'delivered' => true,
        'demonstrates' => true,
        'secured' => true,
        'undeniably' => true,
        'will' => true,
    ];

    /** @var array<string, bool> */
    private static array $emotiveVocabulary = [
        'amazed' => true,
        'angry' => true,
        'anxious' => true,
        'concerned' => true,
        'delighted' => true,
        'disappointed' => true,
        'excited' => true,
        'frustrated' => true,
        'grateful' => true,
        'happy' => true,
        'hopeful' => true,
        'motivated' => true,
        'optimistic' => true,
        'pessimistic' => true,
        'proud' => true,
        'relieved' => true,
        'shocked' => true,
        'thrilled' => true,
        'upset' => true,
        'worried' => true,
    ];

    /** @var array<string, bool> */
    private static array $dialogueVerbs = [
        'asked' => true,
        'explained' => true,
        'noted' => true,
        'replied' => true,
        'responded' => true,
        'said' => true,
        'shared' => true,
        'stated' => true,
        'told' => true,
        'wrote' => true,
    ];

    public function __construct(?EnglishLexicon $lexicon = null)
    {
        $this->lexicon = $lexicon ?? EnglishLexicon::loadDefault();
    }

    public function cleanDocument(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $text = $this->preNormalizeDocument($text);

        $lines = preg_split('/\r\n|\r|\n/', $text);
        if ($lines === false) {
            $lines = [$text];
        }

        $cleanedLines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $cleanedLines[] = '';
                continue;
            }

            $normalized = preg_replace('/\s+/', ' ', $trimmed);
            if (!is_string($normalized)) {
                $normalized = $trimmed;
            }

            $normalized = preg_replace('/^[\-*•·‣‒–—]+\s*/u', '- ', $normalized);
            if (!is_string($normalized)) {
                $normalized = $trimmed;
            }

            $normalized = $this->normalizeInlineSpacing($normalized);

            $normalized = $this->normaliseListIndicators($normalized);

            $cleanedLines[] = $normalized;
        }

        $filtered = $this->filterContextualLines($cleanedLines);
        $filtered = $this->removeUngrammaticalSentences($filtered);

        $result = implode("\n", $filtered);
        $result = preg_replace("/\n{3,}/", "\n\n", $result);
        if (!is_string($result)) {
            $result = implode("\n", $filtered);
        }

        return trim($result);
    }

    private function normaliseListIndicators(string $line): string
    {
        $trimmedLeft = ltrim($line);
        if ($trimmedLeft === '') {
            return $line;
        }

        $indentLength = strlen($line) - strlen($trimmedLeft);
        $indent = $indentLength > 0 ? substr($line, 0, $indentLength) : '';

        $patterns = [
            '/^(?:\(?\d{1,3}\)?)[\.)]\s+(.+)$/u',
            '/^(?:\(?[a-z]{1,3}\)?)[\.)]\s+(.+)$/iu',
            '/^(?:[ivxlcdm]{1,5})[\.)]\s+(.+)$/iu',
            '/^\d+\s*[-–—]\s+(.+)$/u',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $trimmedLeft, $matches)) {
                continue;
            }

            $content = isset($matches[1]) ? trim($matches[1]) : '';
            if ($content === '') {
                continue;
            }

            return $indent . '- ' . $content;
        }

        return $line;
    }

    private function normalizeInlineSpacing(string $line): string
    {
        $result = $line;

        $patterns = [
            '/:(?=\S)/u' => ': ',
            '/,(?=\S)/u' => ', ',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $updated = preg_replace($pattern, $replacement, $result);
            if (is_string($updated)) {
                $result = $updated;
            }
        }

        $replacements = [
            '/(\p{L})(\d)/u' => '$1 $2',
            '/(\d)(\p{L})/u' => '$1 $2',
            '/([a-z])([A-Z])/u' => '$1 $2',
            '/([A-Z]{2,})([A-Z][a-z])/u' => '$1 $2',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $updated = preg_replace($pattern, $replacement, $result);
            if (is_string($updated)) {
                $result = $updated;
            }
        }

        $updated = preg_replace('/\b(\d{1,2}:\d{2})\s*([A-Z]{2,5})\b/u', '$1 $2', $result);
        if (is_string($updated)) {
            $result = $updated;
        }

        $updated = preg_replace('/\b([A-Z]{2,5})\s*(\d{1,2}:\d{2})\b/u', '$1 $2', $result);
        if (is_string($updated)) {
            $result = $updated;
        }

        $updated = preg_replace('/\s{2,}/u', ' ', $result);
        if (is_string($updated)) {
            $result = $updated;
        }

        return trim($result);
    }

    public function rewriteDocument(string $text): string
    {
        $cleaned = $this->cleanDocument($text);
        if ($cleaned === '') {
            return '';
        }

        $sections = $this->segmentContextualSections($cleaned);
        $rewritten = [];
        $fallback = '';
        $fallbackScore = 0.0;

        foreach ($sections as $section) {
            $score = $this->scoreSectionSignificance($section);
            $normalized = $this->rewriteSection($section);

            if ($normalized === '') {
                continue;
            }

            if ($score >= 0.35) {
                $rewritten[] = $normalized;
                continue;
            }

            if ($score > $fallbackScore) {
                $fallbackScore = $score;
                $fallback = $normalized;
            }
        }

        if ($rewritten === [] && $fallback !== '') {
            $rewritten[] = $fallback;
        }

        return implode("\n\n", $rewritten);
    }

    private function rewriteSection(string $section): string
    {
        $lines = preg_split('/\n/', $section);
        if ($lines === false) {
            $lines = [$section];
        }

        $filteredLines = $this->filterContextualLines($lines);
        $normalized = trim(implode("\n", $filteredLines));
        if ($normalized === '') {
            return '';
        }

        $paragraphs = preg_split('/\n\s*\n/', $normalized);
        if ($paragraphs === false) {
            $paragraphs = [$normalized];
        }

        $rewrittenParagraphs = [];
        foreach ($paragraphs as $paragraph) {
            $block = $this->rewriteParagraphBlock($paragraph);
            if ($block === null) {
                continue;
            }

            $rewrittenParagraphs[] = $block;
        }

        return trim(implode("\n\n", $rewrittenParagraphs));
    }

    private function rewriteParagraphBlock(string $paragraph): ?string
    {
        $paragraph = trim($paragraph);
        if ($paragraph === '') {
            return null;
        }

        $lines = preg_split('/\n/', $paragraph);
        if ($lines === false) {
            $lines = [$paragraph];
        }

        $isList = true;
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if (preg_match('/^[-*•·]+\s+/u', $trimmed) !== 1) {
                $isList = false;
                break;
            }
        }

        if ($isList) {
            $items = [];
            foreach ($lines as $line) {
                $content = ltrim($line);
                $content = preg_replace('/^[-*•·]+\s*/u', '', $content);
                if (!is_string($content)) {
                    $content = ltrim($line);
                }

                $content = trim($content);
                if ($content === '') {
                    continue;
                }

                $items[] = '- ' . $this->capitaliseSentence($content, false);
            }

            if ($items === []) {
                return null;
            }

            return implode("\n", $items);
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $paragraph);
        if ($sentences === false) {
            $sentences = [$paragraph];
        }

        $normalizedSentences = [];
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence, " \t\n\r\0\x0B-•·");
            if ($sentence === '') {
                continue;
            }

            if ($this->looksLikeMeaninglessText($sentence)) {
                continue;
            }

            $normalizedSentences[] = $this->capitaliseSentence($sentence, true);
        }

        if ($normalizedSentences === []) {
            return null;
        }

        return implode(' ', $normalizedSentences);
    }

    /**
     * @return array<int, string>
     */
    private function segmentContextualSections(string $text): array
    {
        $paragraphs = preg_split('/\n\s*\n/', $text);
        if ($paragraphs === false) {
            $paragraphs = [$text];
        }

        $sections = [];
        $currentParagraphs = [];
        $currentTokens = [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            $paragraphTokens = $this->collectContentTokens($paragraph, 16);
            $isHeading = $this->isContextualHeading($paragraph);

            if ($isHeading && $currentParagraphs !== []) {
                $sections[] = implode("\n\n", $currentParagraphs);
                $currentParagraphs = [];
                $currentTokens = [];
            }

            $similarity = 1.0;
            if ($currentParagraphs !== []) {
                $overlap = 0;
                foreach ($paragraphTokens as $token) {
                    if (isset($currentTokens[$token])) {
                        $overlap++;
                    }
                }

                $denominator = max(count($paragraphTokens), count($currentTokens), 1);
                $similarity = $denominator === 0 ? 0.0 : $overlap / $denominator;

                $lastParagraph = (string) $currentParagraphs[count($currentParagraphs) - 1];
                if (
                    $similarity < 0.3
                    && !(count($currentParagraphs) === 1 && $this->isContextualHeading($lastParagraph))
                ) {
                    $sections[] = implode("\n\n", $currentParagraphs);
                    $currentParagraphs = [];
                    $currentTokens = [];
                }
            }

            $currentParagraphs[] = $paragraph;

            if ($currentTokens === []) {
                foreach ($paragraphTokens as $token) {
                    $currentTokens[$token] = true;
                }
            } else {
                foreach ($paragraphTokens as $token) {
                    $currentTokens[$token] = true;
                }
            }
        }

        if ($currentParagraphs !== []) {
            $sections[] = implode("\n\n", $currentParagraphs);
        }

        if ($sections === []) {
            $sections[] = trim($text);
        }

        return $sections;
    }

    private function scoreSectionSignificance(string $section): float
    {
        $lines = preg_split('/\n/', $section);
        if ($lines === false) {
            $lines = [$section];
        }

        $candidateLines = 0;
        $meaningfulLines = 0;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $candidateLines++;
            if ($this->looksLikeBoilerplate($trimmed) || $this->looksLikeMeaninglessText($trimmed)) {
                continue;
            }

            $meaningfulLines++;
        }

        $meaningRatio = $candidateLines === 0 ? 0.0 : $meaningfulLines / $candidateLines;

        $contentTokens = $this->collectContentTokens($section, 16);
        $tokenScore = $contentTokens === [] ? 0.0 : min(1.0, count($contentTokens) / 10);

        $sentences = $this->extractSentences($section);
        $validSentences = 0;
        foreach ($sentences as $sentence) {
            if ($this->isGrammaticallySoundSentence($sentence)) {
                $validSentences++;
            }
        }

        $sentenceScore = $sentences === [] ? 0.0 : $validSentences / count($sentences);

        $score = (0.45 * $meaningRatio) + (0.3 * $tokenScore) + (0.25 * $sentenceScore);

        return $this->clamp($score, 0.0, 1.0);
    }

    private function isContextualHeading(string $paragraph): bool
    {
        if ($paragraph === '') {
            return false;
        }

        $line = preg_replace('/\s+/u', ' ', $paragraph);
        if (!is_string($line)) {
            $line = $paragraph;
        }

        $line = trim($line);
        if ($line === '') {
            return false;
        }

        if (preg_match('/[:\-–—]\s*$/u', $line) === 1) {
            return true;
        }

        if (preg_match('/^#[^#]/', $line) === 1) {
            return true;
        }

        $words = preg_split('/\s+/u', $line);
        if ($words === false) {
            $words = [$line];
        }

        if (count($words) <= 8) {
            $upper = 0;
            foreach ($words as $word) {
                if ($word === '') {
                    continue;
                }
                if (preg_match('/^[A-Z0-9\p{Lu}][A-Z0-9\p{Lu}\-]*$/u', $word) === 1) {
                    $upper++;
                }
            }

            if ($upper >= max(2, (int) floor(count($words) / 2))) {
                return true;
            }
        }

        if (count($words) <= 4 && mb_strlen($line, 'UTF-8') <= 48) {
            return true;
        }

        return false;
    }

    /**
     * @return array<int, array{token: string, count: int}>
     */
    public function extractKeywords(string $text, int $limit = 10): array
    {
        $cleaned = strtolower($this->cleanDocument($text));
        if ($cleaned === '') {
            return [];
        }

        $tokens = preg_split('/[^a-z0-9\']+/i', $cleaned);
        if ($tokens === false) {
            $tokens = [];
        }

        $acronymLookup = [];
        $acronymMatches = [];
        preg_match_all('/\b[A-Z]{2,5}\b/u', $text, $acronymMatches);
        if (isset($acronymMatches[0]) && is_array($acronymMatches[0])) {
            foreach ($acronymMatches[0] as $match) {
                if (!is_string($match)) {
                    continue;
                }

                $lower = strtolower($match);
                if ($lower === '') {
                    continue;
                }

                $acronymLookup[$lower] = true;
            }
        }

        $counts = [];
        foreach ($tokens as $token) {
            if (!is_string($token)) {
                continue;
            }

            $normalized = strtolower($token);
            if ($normalized === '' || is_numeric($normalized)) {
                continue;
            }

            $isAcronym = isset($acronymLookup[$normalized]);
            if (strlen($normalized) < 3 && !$isAcronym) {
                continue;
            }

            if (isset(self::$stopwords[$normalized])) {
                continue;
            }

            if (!$isAcronym && !$this->lexicon->contains($normalized) && !$this->looksLikeName($normalized)) {
                continue;
            }

            if (!isset($counts[$normalized])) {
                $counts[$normalized] = 0;
            }

            $counts[$normalized] += $isAcronym ? 2 : 1;
        }

        if ($counts === []) {
            return [];
        }

        arsort($counts, SORT_NUMERIC);

        $keywords = [];
        foreach ($counts as $token => $count) {
            $keywords[] = ['token' => $token, 'count' => (int) $count];
            if (count($keywords) >= $limit) {
                break;
            }
        }

        return $keywords;
    }

    /**
     * @return array<int, array{phrase: string, score: float}>
     */
    public function extractKeyPhrases(string $text, int $limit = 12): array
    {
        $source = $this->cleanDocument($text);
        if ($source === '') {
            $source = $text;
        }

        $source = trim($source);
        if ($source === '') {
            return [];
        }

        $sentences = preg_split('/(?<=[.!?])\s+|\n+/u', $source);
        if ($sentences === false) {
            $sentences = [$source];
        }

        $scores = [];
        $phrases = [];

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }

            preg_match_all('/\b[\p{L}\p{N}][\p{L}\p{N}\'-]*\b/u', $sentence, $matches);
            $tokens = is_array($matches[0] ?? null) ? $matches[0] : [];
            if ($tokens === []) {
                continue;
            }

            $buffer = [];
            foreach ($tokens as $token) {
                if (!is_string($token)) {
                    continue;
                }

                $normalized = mb_strtolower($token, 'UTF-8');
                if ($this->isPhraseBoundaryToken($normalized)) {
                    if ($buffer !== []) {
                        $this->scorePhraseCandidate($buffer, $scores, $phrases);
                        $buffer = [];
                    }
                    continue;
                }

                $buffer[] = [
                    'normalized' => $normalized,
                    'original' => $token,
                ];
            }

            if ($buffer !== []) {
                $this->scorePhraseCandidate($buffer, $scores, $phrases);
            }
        }

        if ($scores === []) {
            return [];
        }

        arsort($scores, SORT_NUMERIC);
        $maxScore = max($scores);

        $results = [];
        foreach ($scores as $key => $score) {
            $phrase = $phrases[$key] ?? $key;
            $normalizedScore = $maxScore > 0.0 ? round(min(1.0, $score / $maxScore), 3) : 0.0;

            $results[] = [
                'phrase' => $phrase,
                'score' => $normalizedScore,
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * @return array{
     *     fingerprint: string,
     *     terms: array<int, string>,
     *     term_weights: array<string, float>,
     *     phrase_weights: array<string, float>,
     *     key_phrases: array<int, array{phrase: string, score: float}>
     * }
     */
    public function buildSemanticProfile(string $text, int $limit = 12): array
    {
        $source = $this->cleanDocument($text);
        if ($source === '') {
            $source = $text;
        }

        $source = trim($source);
        if ($source === '') {
            return [
                'fingerprint' => '',
                'terms' => [],
                'term_weights' => [],
                'phrase_weights' => [],
                'key_phrases' => [],
            ];
        }

        preg_match_all('/\b[\p{L}\p{N}][\p{L}\p{N}\'-]*\b/u', $source, $matches);
        $tokens = is_array($matches[0] ?? null) ? $matches[0] : [];

        $frequencies = [];
        $totalTokens = 0;
        foreach ($tokens as $token) {
            if (!is_string($token)) {
                continue;
            }

            $normalized = mb_strtolower($token, 'UTF-8');
            $normalized = preg_replace('/[^\p{L}\p{N}\'-]+/u', '', $normalized);
            if (!is_string($normalized)) {
                $normalized = mb_strtolower($token, 'UTF-8');
            }

            $normalized = trim($normalized);
            if ($normalized === '' || is_numeric($normalized)) {
                continue;
            }

            if ($this->isPhraseBoundaryToken($normalized)) {
                continue;
            }

            $frequencies[$normalized] = ($frequencies[$normalized] ?? 0) + 1;
            $totalTokens++;
        }

        $termWeights = [];
        foreach ($frequencies as $token => $count) {
            $lengthWeight = min(0.4, max(0.0, (mb_strlen($token, 'UTF-8') - 4) / 12));
            $freqWeight = $totalTokens > 0 ? ($count / $totalTokens) * 4.2 : 0.0;
            $lexiconBonus = $this->lexicon->contains($token) || $this->looksLikeName($token) ? 0.2 : 0.0;
            $score = max(0.05, min(1.0, 0.35 + $freqWeight + $lengthWeight + $lexiconBonus));
            $termWeights[$token] = round($score, 3);
        }

        arsort($termWeights, SORT_NUMERIC);
        if (count($termWeights) > $limit * 2) {
            $termWeights = array_slice($termWeights, 0, $limit * 2, true);
        }

        $keyPhrases = $this->extractKeyPhrases($source, $limit);
        $phraseWeights = [];
        foreach ($keyPhrases as $phrase) {
            $normalized = mb_strtolower($phrase['phrase'], 'UTF-8');
            $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
            $normalized = trim($normalized);
            if ($normalized === '') {
                continue;
            }

            $phraseWeights[$normalized] = max(0.0, min(1.0, (float) $phrase['score']));
        }

        if ($phraseWeights !== []) {
            arsort($phraseWeights, SORT_NUMERIC);
            $phraseWeights = array_slice($phraseWeights, 0, $limit, true);
        }

        $terms = array_keys($termWeights);
        $terms = array_slice($terms, 0, $limit);

        $fingerprintSeed = $terms;
        if ($fingerprintSeed === [] && $phraseWeights !== []) {
            $fingerprintSeed = array_keys($phraseWeights);
        }

        $fingerprint = '';
        if ($fingerprintSeed !== []) {
            $fingerprint = substr(hash('sha256', implode('|', $fingerprintSeed)), 0, 48);
        }

        return [
            'fingerprint' => $fingerprint,
            'terms' => $terms,
            'term_weights' => $termWeights,
            'phrase_weights' => $phraseWeights,
            'key_phrases' => $keyPhrases,
        ];
    }

    /**
     * Produce short semantic highlights that can be attached to crawler results or search matches.
     *
     * @return array<int, array{phrase: string, snippet: string}>
     */
    public function semanticHighlights(string $text, int $limit = 3): array
    {
        $limit = max(1, min(10, $limit));

        $profile = $this->buildSemanticProfile($text, $limit * 2);
        $phrases = [];

        foreach ($profile['key_phrases'] as $entry) {
            if (is_array($entry)) {
                $phrase = isset($entry['phrase']) ? (string) $entry['phrase'] : '';
                $score = isset($entry['score']) ? (float) $entry['score'] : 0.0;
            } elseif (is_string($entry)) {
                $phrase = $entry;
                $score = 0.0;
            } else {
                continue;
            }

            $normalised = trim(mb_strtolower($phrase, 'UTF-8'));
            if ($normalised === '') {
                continue;
            }

            $phrases[$normalised] = [
                'phrase' => trim($phrase),
                'score' => max(0.0, min(1.0, $score)),
            ];
        }

        if ($phrases === [] && isset($profile['terms'])) {
            foreach ($profile['terms'] as $index => $term) {
                if (!is_string($term)) {
                    continue;
                }

                $normalised = trim(mb_strtolower($term, 'UTF-8'));
                if ($normalised === '') {
                    continue;
                }

                $phrases[$normalised] = [
                    'phrase' => trim($term),
                    'score' => 0.25 - ($index * 0.01),
                ];
            }
        }

        if ($phrases === []) {
            return [];
        }

        uasort($phrases, static fn(array $left, array $right): int => ($right['score'] <=> $left['score']));

        $document = $this->cleanDocument($text);
        if ($document === '') {
            $document = trim($text);
        }

        $candidates = $this->extractSemanticCandidateSentences($document);
        if ($candidates === []) {
            return [];
        }

        $highlights = [];
        $usedSentences = [];

        foreach ($phrases as $identifier => $payload) {
            if (count($highlights) >= $limit) {
                break;
            }

            $needle = ' ' . $identifier . ' ';
            $needleBare = $identifier;

            foreach ($candidates as $sentence) {
                $lowerSentence = ' ' . mb_strtolower($sentence, 'UTF-8') . ' ';
                if (isset($usedSentences[$sentence])) {
                    continue;
                }

                if (
                    mb_stripos($lowerSentence, $needle) === false
                    && mb_stripos($lowerSentence, $needleBare) === false
                ) {
                    $tokens = preg_split('/\s+/u', $identifier) ?: [];
                    $allPresent = true;
                    foreach ($tokens as $token) {
                        $token = trim((string) $token);
                        if ($token === '') {
                            continue;
                        }
                        if (mb_stripos($lowerSentence, ' ' . $token . ' ') === false && mb_stripos($lowerSentence, $token) === false) {
                            $allPresent = false;
                            break;
                        }
                    }

                    if (!$allPresent) {
                        continue;
                    }
                }

                $usedSentences[$sentence] = true;

                $snippet = $this->normaliseHighlightSnippet($sentence);
                if ($snippet === '') {
                    continue;
                }

                $highlights[] = [
                    'phrase' => $payload['phrase'],
                    'snippet' => $snippet,
                ];

                break;
            }
        }

        return array_slice($highlights, 0, $limit);
    }

    /**
     * @return array<int, string>
     */
    private function extractSemanticCandidateSentences(string $text): array
    {
        $normalised = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($normalised === '') {
            return [];
        }

        $sentenceSplits = preg_split('/(?<=[\.!?])\s+(?=[\p{L}\d])/u', $normalised) ?: [];
        $lineSplits = preg_split('/\n+/', $normalised) ?: [];

        $candidates = [];

        foreach (array_merge($sentenceSplits, $lineSplits) as $segment) {
            if (!is_string($segment)) {
                continue;
            }

            $trimmed = trim($segment);
            if ($trimmed === '' || mb_strlen($trimmed, 'UTF-8') < 12) {
                continue;
            }

            if (str_starts_with($trimmed, '- ')) {
                $trimmed = trim(substr($trimmed, 2));
            }

            $trimmed = $this->normaliseHighlightSnippet($trimmed);
            if ($trimmed === '') {
                continue;
            }

            $candidates[$trimmed] = true;
        }

        return array_keys($candidates);
    }

    private function normaliseHighlightSnippet(string $snippet): string
    {
        $normalised = preg_replace('/\s+/u', ' ', trim($snippet));
        if (!is_string($normalised)) {
            $normalised = trim($snippet);
        }

        if ($normalised === '') {
            return '';
        }

        if (mb_strlen($normalised, 'UTF-8') > 220) {
            $normalised = trim(mb_substr($normalised, 0, 220, 'UTF-8')) . '…';
        }

        return $normalised;
    }

    /**
     * @param array<int, array{normalized: string, original: string}> $tokens
     * @param array<string, float> $scores
     * @param array<string, string> $phrases
     */
    private function scorePhraseCandidate(array $tokens, array &$scores, array &$phrases): void
    {
        $normalizedTokens = [];
        $originalTokens = [];

        foreach ($tokens as $token) {
            $normalized = preg_replace('/[^\p{L}\p{N}\'-]+/u', '', $token['normalized']);
            if (!is_string($normalized)) {
                $normalized = $token['normalized'];
            }

            $normalized = trim($normalized);
            if ($normalized === '') {
                continue;
            }

            $normalizedTokens[] = $normalized;
            $originalTokens[] = $token['original'];
        }

        if ($normalizedTokens === []) {
            return;
        }

        $key = implode(' ', $normalizedTokens);
        if (mb_strlen($key, 'UTF-8') < 4) {
            return;
        }

        $length = count($normalizedTokens);
        $uniqueCount = count(array_unique($normalizedTokens));
        $lengthWeight = 1.0 + min(0.9, ($length - 1) * 0.35);
        $diversityWeight = 1.0 + min(0.6, ($uniqueCount - 1) * 0.2);
        $entropyBonus = 0.0;

        foreach ($normalizedTokens as $token) {
            if (mb_strlen($token, 'UTF-8') >= 7) {
                $entropyBonus += 0.05;
            }
        }

        $score = ($lengthWeight * $diversityWeight) + $entropyBonus;

        $scores[$key] = ($scores[$key] ?? 0.0) + $score;
        if (!isset($phrases[$key])) {
            $phrases[$key] = $this->formatPhraseForDisplay($originalTokens);
        }
    }

    /**
     * @param array<int, string> $tokens
     */
    private function formatPhraseForDisplay(array $tokens): string
    {
        $segments = [];
        foreach ($tokens as $token) {
            if (!is_string($token)) {
                continue;
            }

            $segments[] = trim($token, " \t\n\r\0\x0B-");
        }

        $joined = implode(' ', array_filter($segments, static fn(string $segment): bool => $segment !== ''));
        $normalized = preg_replace('/\s+/', ' ', $joined);
        if (!is_string($normalized)) {
            $normalized = $joined;
        }

        return trim($normalized);
    }

    private function isPhraseBoundaryToken(string $token): bool
    {
        if ($token === '') {
            return true;
        }

        if (isset(self::$stopwords[$token]) || isset(self::$pronouns[$token]) || isset(self::$determiners[$token])) {
            return true;
        }

        if (mb_strlen($token, 'UTF-8') <= 2 && !$this->lexicon->contains($token) && !$this->looksLikeName($token)) {
            return true;
        }

        return false;
    }

    /**
     * @return array<int, array{token: string, count: int, suggestions: array<int, string>}>
     */
    public function spellCheck(string $text, int $maxSuggestions = 3): array
    {
        $tokens = preg_split('/[^A-Za-z\']+/u', $text);
        if ($tokens === false) {
            $tokens = [];
        }

        $counts = [];
        foreach ($tokens as $token) {
            if (!is_string($token)) {
                continue;
            }

            $normalized = strtolower($token);
            if ($normalized === '' || strlen($normalized) < 3) {
                continue;
            }

            if ($this->lexicon->contains($normalized)) {
                continue;
            }

            if (!isset($counts[$normalized])) {
                $counts[$normalized] = 0;
            }
            $counts[$normalized]++;
        }

        if ($counts === []) {
            return [];
        }

        $results = [];
        foreach ($counts as $token => $count) {
            $suggestions = $this->lexicon->suggest($token, $maxSuggestions);
            $results[] = [
                'token' => $token,
                'count' => $count,
                'suggestions' => $suggestions,
            ];
        }

        usort(
            $results,
            static function (array $left, array $right): int {
                if ($left['count'] === $right['count']) {
                    return $left['token'] <=> $right['token'];
                }

                return ($left['count'] < $right['count']) ? 1 : -1;
            }
        );

        return $results;
    }

    /** @var array<int, string> */
    private static array $transitionSignals = [
        'however',
        'therefore',
        'moreover',
        'furthermore',
        'additionally',
        'in addition',
        'meanwhile',
        'consequently',
        'as a result',
        'on the other hand',
        'first,',
        'second,',
        'third,',
        'next,',
        'finally,',
        'in contrast',
        'similarly',
        'overall,',
    ];

    /** @var array<int, string> */
    private static array $conclusionSignals = [
        'in conclusion',
        'in summary',
        'overall',
        'to conclude',
        'ultimately',
        'finally',
        'in closing',
    ];

    /** @var array<int, string> */
    private static array $introductionSignals = [
        'in this',
        'this report',
        'this article',
        'this analysis',
        'the following',
        'we explore',
        'we examine',
        'we discuss',
    ];

    /**
     * @return array{
     *     original: string,
     *     cleaned: string,
     *     rewritten: string,
     *     keywords: array<int, array{token: string, count: int}>,
     *     spelling: array<int, array{token: string, count: int, suggestions: array<int, string>}>,
     *     qa: array<int, array{question: string, answer: string, response: string}>,
     *     analytics: array<string, mixed>,
     *     is_meaningful: bool
     * }
     */
    public function analyseDocument(string $text): array
    {
        $cleaned = $this->cleanDocument($text);
        $rewritten = $this->rewriteDocument($text);
        $keywords = $this->extractKeywords($text);
        $analytics = $this->analyseNarrativeSignals($text, $cleaned, $keywords);
        $isMeaningful = $this->evaluateMeaningfulness($text, $cleaned);

        return [
            'original' => $text,
            'cleaned' => $cleaned,
            'rewritten' => $rewritten,
            'keywords' => $keywords,
            'spelling' => $this->spellCheck($text),
            'qa' => $this->generateQuestionAnswerPairs($text),
            'analytics' => $analytics,
            'is_meaningful' => $isMeaningful,
        ];
    }

    private function evaluateMeaningfulness(string $original, string $cleaned): bool
    {
        $source = $cleaned !== '' ? $cleaned : $original;
        if ($source === '') {
            return false;
        }

        $lines = preg_split("/\r\n|\r|\n/u", $source);
        if ($lines === false) {
            $lines = [$source];
        }

        $meaningfulSentenceDetected = false;
        $meaningfulBulletDetected = false;
        $tokenTotal = 0;
        $recognizedTotal = 0;
        $stopwordTotal = 0;
        $hasVerbCandidate = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $isBullet = preg_match('/^[-•·]+\s+/u', ltrim($line)) === 1;
            $normalizedLine = $isBullet ? preg_replace('/^[-•·]+\s*/u', '', ltrim($line)) : $trimmed;
            if (!is_string($normalizedLine)) {
                $normalizedLine = $trimmed;
            }

            $sentenceCandidates = preg_split('/(?<=[.!?])\s+/u', $normalizedLine) ?: [$normalizedLine];
            foreach ($sentenceCandidates as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '') {
                    continue;
                }

                if ($this->isGrammaticallySoundSentence($candidate)) {
                    $meaningfulSentenceDetected = true;
                    break 2;
                }
            }

            preg_match_all('/\b[\p{L}\']+\b/u', $normalizedLine, $wordMatches);
            $tokens = is_array($wordMatches[0] ?? null) ? $wordMatches[0] : [];
            if ($tokens === []) {
                continue;
            }

            $recognizedLine = 0;
            $stopwordsLine = 0;
            $lowerLineTokens = [];
            foreach ($tokens as $token) {
                $lower = mb_strtolower((string) $token, 'UTF-8');
                if ($lower === '') {
                    continue;
                }

                $tokenTotal++;
                $lowerLineTokens[] = $lower;

                if (isset(self::$stopwords[$lower])) {
                    $stopwordTotal++;
                    $stopwordsLine++;
                }

                if ($this->lexicon->contains($lower) || $this->looksLikeName($lower)) {
                    $recognizedTotal++;
                    $recognizedLine++;
                }
            }

            if ($lowerLineTokens !== []) {
                $lowerLine = mb_strtolower($normalizedLine, 'UTF-8');
                if ($this->containsVerbCandidate($lowerLine, $tokens)) {
                    $hasVerbCandidate = true;
                }

                if (
                    $isBullet
                    && $recognizedLine >= 1
                    && $this->containsVerbCandidate($lowerLine, $tokens)
                ) {
                    $meaningfulBulletDetected = true;
                }
            }
        }

        if ($meaningfulSentenceDetected || $meaningfulBulletDetected) {
            return true;
        }

        if ($tokenTotal < 5) {
            return false;
        }

        if ($recognizedTotal === 0) {
            return false;
        }

        if (!$hasVerbCandidate) {
            return false;
        }

        $recognizedRatio = $recognizedTotal / max(1, $tokenTotal);
        if ($recognizedRatio < 0.45) {
            return false;
        }

        if ($stopwordTotal === 0 && $recognizedRatio < 0.65) {
            return false;
        }

        return true;
    }

    /**
     * @param array<int, array{token: string, count: int}> $keywords
     * @return array<string, mixed>
     */
    private function analyseNarrativeSignals(string $original, string $cleaned, array $keywords): array
    {
        $source = $cleaned !== '' ? $cleaned : $original;

        if ($source === '') {
            $sentiment = $this->analyseSentiment('');
            $factuality = $this->assessFactuality('');
            $conversation = $this->detectConversationSignals($original);
            $intent = $this->classifyIntent('', $conversation, $sentiment, $factuality);

            return [
                'sentiment' => $sentiment,
                'intent' => $intent,
                'factuality' => $factuality,
                'conversation' => $conversation,
                'topics' => $this->buildTopicHighlights('', []),
                'narrative' => $this->evaluateNarrativeSignals(''),
                'writing_quality' => $this->evaluateWritingQuality($original, '', [], $intent, $factuality),
                'grammar' => $this->extractGrammarInsights(''),
            ];
        }

        $factuality = $this->assessFactuality($source);
        $sentiment = $this->analyseSentiment($source);
        $conversation = $this->detectConversationSignals($original !== '' ? $original : $source);
        $intent = $this->classifyIntent($source, $conversation, $sentiment, $factuality);
        $topics = $this->buildTopicHighlights($source, $keywords);
        $narrative = $this->evaluateNarrativeSignals($source);
        $writingQuality = $this->evaluateWritingQuality($original !== '' ? $original : $source, $source, $keywords, $intent, $factuality);

        return [
            'sentiment' => $sentiment,
            'intent' => $intent,
            'factuality' => $factuality,
            'conversation' => $conversation,
            'topics' => $topics,
            'narrative' => $narrative,
            'writing_quality' => $writingQuality,
            'grammar' => $this->extractGrammarInsights($source),
        ];
    }

    /**
     * @return array<int, array{question: string, answer: string, response: string}>
     */
    public function generateQuestionAnswerPairs(string $text, int $limit = 5): array
    {
        $cleaned = $this->cleanDocument($text);
        if ($cleaned === '') {
            return [];
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $cleaned);
        if ($sentences === false) {
            $sentences = [$cleaned];
        }

        $normalizedSentences = [];
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }

            $normalizedSentences[] = $sentence;
        }

        if ($normalizedSentences === []) {
            return [];
        }

        $keywords = $this->extractKeywords($text, $limit * 2);
        $keywordTokens = array_map(
            static fn(array $entry): string => strtolower($entry['token']),
            $keywords
        );

        $pairs = [];
        $questionLookup = [];
        $usedSentenceIndexes = [];

        foreach ($keywordTokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            foreach ($normalizedSentences as $index => $sentence) {
                if (isset($usedSentenceIndexes[$index])) {
                    continue;
                }

                if (stripos($sentence, $token) === false) {
                    continue;
                }

                $question = sprintf('What does the text say about %s?', $this->formatKeywordForQuestion($token));
                if (isset($questionLookup[$question])) {
                    continue;
                }

                $answer = $this->ensureSentencePunctuation($sentence);

                $pairs[] = [
                    'question' => $question,
                    'answer' => $answer,
                    'response' => $answer,
                ];

                $questionLookup[$question] = true;
                $usedSentenceIndexes[$index] = true;

                if (count($pairs) >= $limit) {
                    return $pairs;
                }

                break;
            }
        }

        if (count($pairs) < $limit) {
            $grammar = $this->extractGrammarInsights($cleaned);
            $entityAssociations = is_array($grammar['entity_associations'] ?? null) ? $grammar['entity_associations'] : [];

            foreach ($entityAssociations as $association) {
                if (!is_array($association)) {
                    continue;
                }

                $entity = trim((string) ($association['entity'] ?? ''));
                if ($entity === '') {
                    continue;
                }

                $entitySentence = $this->findSentenceForEntity($normalizedSentences, $entity);
                $verbValues = is_array($association['verbs'] ?? null) ? $association['verbs'] : [];
                $adjectiveValues = is_array($association['adjectives'] ?? null) ? $association['adjectives'] : [];
                $verbs = array_values(array_filter(array_map(static fn($value): string => trim((string) $value), $verbValues)));
                $adjectives = array_values(array_filter(array_map(static fn($value): string => trim((string) $value), $adjectiveValues)));

                if ($verbs !== [] && count($pairs) < $limit) {
                    $question = sprintf('What does %s do in the text?', $entity);
                    if (!isset($questionLookup[$question])) {
                        $answer = $entitySentence;
                        if ($answer === null) {
                            $verbList = $this->formatList($verbs);
                            if ($verbList !== '') {
                                $answer = $this->ensureSentencePunctuation(sprintf('The text notes that %s %s', $entity, $verbList));
                            }
                        }

                        if ($answer !== null) {
                            $pairs[] = [
                                'question' => $question,
                                'answer' => $answer,
                                'response' => $answer,
                            ];

                            $questionLookup[$question] = true;

                            if (count($pairs) >= $limit) {
                                break;
                            }
                        }
                    }
                }

                if ($adjectives !== [] && count($pairs) < $limit) {
                    $question = sprintf('How is %s described in the text?', $entity);
                    if (!isset($questionLookup[$question])) {
                        $adjectiveList = $this->formatList($adjectives);
                        if ($adjectiveList === '') {
                            continue;
                        }

                        $answer = $entitySentence;
                        if ($answer === null || stripos($answer, $adjectiveList) === false) {
                            $answer = $this->ensureSentencePunctuation(sprintf('%s is described as %s', $entity, $adjectiveList));
                        }

                        if ($answer !== null) {
                            $pairs[] = [
                                'question' => $question,
                                'answer' => $answer,
                                'response' => $answer,
                            ];

                            $questionLookup[$question] = true;

                            if (count($pairs) >= $limit) {
                                break;
                            }
                        }
                    }
                }

                if (count($pairs) >= $limit) {
                    break;
                }
            }
        }

        if ($pairs === []) {
            $primarySentence = $this->ensureSentencePunctuation($normalizedSentences[0]);
            $question = 'What is the main point of the text?';

            $pairs[] = [
                'question' => $question,
                'answer' => $primarySentence,
                'response' => $primarySentence,
            ];

            $questionLookup[$question] = true;
        }

        if (count($pairs) < $limit) {
            foreach ($normalizedSentences as $index => $sentence) {
                if (isset($usedSentenceIndexes[$index])) {
                    continue;
                }

                $question = sprintf('What additional detail does the text provide in sentence %d?', $index + 1);
                if (isset($questionLookup[$question])) {
                    continue;
                }

                $answer = $this->ensureSentencePunctuation($sentence);

                $pairs[] = [
                    'question' => $question,
                    'answer' => $answer,
                    'response' => $answer,
                ];

                $questionLookup[$question] = true;
                $usedSentenceIndexes[$index] = true;

                if (count($pairs) >= $limit) {
                    break;
                }
            }
        }

        return $pairs;
    }

    /**
     * @return array{score: float, label: string, magnitude: float, positive_terms: array<int, string>, negative_terms: array<int, string>}
     */
    private function analyseSentiment(string $text): array
    {
        $tokens = $this->tokenise($text);
        if ($tokens === []) {
            return [
                'score' => 0.0,
                'label' => 'neutral',
                'magnitude' => 0.0,
                'positive_terms' => [],
                'negative_terms' => [],
            ];
        }

        $positiveHits = [];
        $negativeHits = [];
        $positiveCount = 0;
        $negativeCount = 0;

        foreach ($tokens as $token) {
            if (isset(self::$positiveSentimentWords[$token])) {
                $positiveHits[$token] = true;
                $positiveCount++;
            }

            if (isset(self::$negativeSentimentWords[$token])) {
                $negativeHits[$token] = true;
                $negativeCount++;
            }
        }

        $total = $positiveCount + $negativeCount;
        $score = 0.0;
        if ($total > 0) {
            $score = ($positiveCount - $negativeCount) / $total;
        }

        $label = 'neutral';
        if ($score > 0.15) {
            $label = 'positive';
        } elseif ($score < -0.15) {
            $label = 'negative';
        }

        $magnitude = abs($score);

        return [
            'score' => round($score, 4),
            'label' => $label,
            'magnitude' => round($magnitude, 4),
            'positive_terms' => $this->mapToList($positiveHits),
            'negative_terms' => $this->mapToList($negativeHits),
        ];
    }

    /**
     * @param array<string, mixed> $conversation
     * @param array<string, mixed> $sentiment
     * @param array<string, mixed> $factuality
     * @return array{primary: string, confidence: float, signals: array<string, float>, explanations: array<int, string>}
     */
    private function classifyIntent(string $text, array $conversation, array $sentiment, array $factuality): array
    {
        $lower = strtolower($text);
        $signals = [
            'informative' => 0.0,
            'persuasive' => 0.0,
            'instructional' => 0.0,
            'narrative' => 0.0,
            'expressive' => 0.0,
            'conversational' => 0.0,
        ];
        $explanations = [];

        if ($text !== '') {
            $signals['informative'] += 0.2;
        }

        $informativePatterns = [
            'according to',
            'analysis',
            'data shows',
            'evidence',
            'findings',
            'reported',
            'research',
            'study',
        ];

        foreach ($informativePatterns as $pattern) {
            if (strpos($lower, $pattern) !== false) {
                $signals['informative'] += 0.6;
                $explanations[] = 'References to research or evidence suggest an informative tone.';
                break;
            }
        }

        $persuasivePatterns = [
            'should ',
            'must ',
            'we urge',
            'we recommend',
            'need to',
            'call to action',
            'imperative',
            'critical that',
        ];
        foreach ($persuasivePatterns as $pattern) {
            if (strpos($lower, $pattern) !== false) {
                $signals['persuasive'] += 0.8;
                $explanations[] = 'Directive language indicates a persuasive intent.';
                break;
            }
        }

        $instructionalPatterns = [
            'how to',
            'step ',
            'first,',
            'next,',
            'finally,',
            'guide',
            'instructions',
            'procedure',
        ];
        foreach ($instructionalPatterns as $pattern) {
            if (strpos($lower, $pattern) !== false) {
                $signals['instructional'] += 0.8;
                $explanations[] = 'Sequenced steps signal an instructional intent.';
                break;
            }
        }

        $narrativePatterns = [
            'i remember',
            'i was',
            'we were',
            'story',
            'journey',
            'experience',
            'chapter',
            'narrative',
        ];
        foreach ($narrativePatterns as $pattern) {
            if (strpos($lower, $pattern) !== false) {
                $signals['narrative'] += 0.7;
                $explanations[] = 'Personal anecdotes contribute to a narrative style.';
                break;
            }
        }

        $expressivePatterns = [
            'i feel',
            'i believe',
            'i think',
            'excited',
            'thrilled',
            'worried',
            'anxious',
            'frustrated',
            'grateful',
            'proud',
            'happy',
            'angry',
        ];
        foreach ($expressivePatterns as $pattern) {
            if (strpos($lower, $pattern) !== false) {
                $signals['expressive'] += 0.8;
                $explanations[] = 'Emotion-rich phrasing highlights an expressive intent.';
                break;
            }
        }

        if (!empty($conversation['is_conversational'])) {
            $signals['conversational'] += 0.9;
            $explanations[] = 'Dialogue structure indicates an interactive exchange.';
        }

        $questionCount = 0;
        if (isset($conversation['questions']) && is_array($conversation['questions'])) {
            $questionCount = count($conversation['questions']);
        }
        if ($questionCount > 0) {
            $signals['conversational'] += min(1.0, 0.3 + ($questionCount * 0.15));
            $explanations[] = 'Questions embedded in the text suggest conversational intent.';
        }

        if (strpos($lower, 'you ') !== false || strpos($lower, 'your ') !== false) {
            $signals['conversational'] += 0.3;
        }

        $magnitude = 0.0;
        if (isset($sentiment['magnitude'])) {
            $magnitude = (float) $sentiment['magnitude'];
        } elseif (isset($sentiment['score'])) {
            $magnitude = abs((float) $sentiment['score']);
        }
        if ($magnitude >= 0.35) {
            $signals['expressive'] += min(0.8, $magnitude);
        }

        $evidenceCount = 0;
        if (isset($factuality['evidence']['verifiable_claims']) && is_array($factuality['evidence']['verifiable_claims'])) {
            $evidenceCount = count($factuality['evidence']['verifiable_claims']);
        }
        if ($evidenceCount > 0 || (($factuality['score'] ?? 0.0) >= 0.6)) {
            $signals['informative'] += 0.9;
            $explanations[] = 'Concrete claims strengthen the informative intent.';
        }

        if (($factuality['score'] ?? 0.5) < 0.45) {
            $signals['expressive'] += 0.4;
            $signals['persuasive'] += 0.2;
        }

        $total = 0.0;
        foreach ($signals as $value) {
            $total += max(0.0, $value);
        }
        if ($total <= 0) {
            $total = 1.0;
            $signals['informative'] = 1.0;
        }

        $normalised = [];
        foreach ($signals as $key => $value) {
            $normalised[$key] = round(max(0.0, $value) / $total, 4);
        }

        $primary = 'informative';
        $maxScore = -1.0;
        foreach ($normalised as $key => $value) {
            if ($value > $maxScore) {
                $maxScore = $value;
                $primary = $key;
            }
        }

        return [
            'primary' => $primary,
            'confidence' => round(max(0.0, $maxScore), 4),
            'signals' => $normalised,
            'explanations' => $this->uniqueStrings($explanations),
        ];
    }

    /**
     * @return array{
     *     classification: string,
     *     score: float,
     *     evidence: array{
     *         verifiable_claims: array<int, string>,
     *         speculative_phrases: array<int, string>,
     *         emotive_phrases: array<int, string>
     *     }
     * }
     */
    private function assessFactuality(string $text): array
    {
        if ($text === '') {
            return [
                'classification' => 'opinion',
                'score' => 0.5,
                'evidence' => [
                    'verifiable_claims' => [],
                    'speculative_phrases' => [],
                    'emotive_phrases' => [],
                ],
            ];
        }

        $verifiable = [];
        $speculative = [];
        $emotive = [];

        $numericPattern = '/\b\d+(?:\.\d+)?\s?(?:%|percent|million|billion|k|thousand)?\b/iu';
        if (preg_match_all($numericPattern, $text, $matches)) {
            foreach ($matches[0] as $match) {
                $verifiable[$match] = true;
            }
        }

        if (preg_match_all('/\b(19|20)\d{2}\b/u', $text, $yearMatches)) {
            foreach ($yearMatches[0] as $match) {
                $verifiable[$match] = true;
            }
        }

        if (preg_match_all('/https?:\/\/[^\s)]+/u', $text, $linkMatches)) {
            foreach ($linkMatches[0] as $match) {
                $verifiable[$match] = true;
            }
        }

        $evidencePhrases = [
            'according to',
            'data from',
            'evidence from',
            'research from',
            'study by',
            'reported by',
            'analysis by',
        ];
        $lower = strtolower($text);
        foreach ($evidencePhrases as $phrase) {
            if (strpos($lower, $phrase) !== false) {
                $verifiable[$phrase] = true;
            }
        }

        foreach (self::$speculativeVocabulary as $term => $flag) {
            if ($flag !== true) {
                continue;
            }
            if (preg_match('/\b' . preg_quote($term, '/') . '\b/u', $lower)) {
                $speculative[$term] = true;
            }
        }

        foreach (self::$emotiveVocabulary as $term => $flag) {
            if ($flag !== true) {
                continue;
            }
            if (preg_match('/\b' . preg_quote($term, '/') . '\b/u', $lower)) {
                $emotive[$term] = true;
            }
        }

        $evidenceCount = count($verifiable);
        $speculativeCount = count($speculative);
        $total = $evidenceCount + $speculativeCount;

        $score = 0.5;
        if ($total > 0) {
            $score = 0.5 + (($evidenceCount - $speculativeCount) / $total) * 0.5;
        }
        $score = $this->clamp($score, 0.0, 1.0);

        $classification = 'opinion';
        if ($score >= 0.65) {
            $classification = 'factual';
        } elseif ($score <= 0.35) {
            $classification = 'speculative';
        }

        return [
            'classification' => $classification,
            'score' => round($score, 4),
            'evidence' => [
                'verifiable_claims' => array_slice($this->mapToList($verifiable), 0, 12),
                'speculative_phrases' => array_slice($this->mapToList($speculative), 0, 12),
                'emotive_phrases' => array_slice($this->mapToList($emotive), 0, 12),
            ],
        ];
    }

    /**
     * @return array{is_conversational: bool, participants: array<int, string>, questions: array<int, string>, dialogue_segments: array<int, array{speaker: string, utterance: string}>, narrative_verbs: array<int, string>}
     */
    private function detectConversationSignals(string $text): array
    {
        if ($text === '') {
            return [
                'is_conversational' => false,
                'participants' => [],
                'questions' => [],
                'dialogue_segments' => [],
                'narrative_verbs' => [],
            ];
        }

        $participants = [];
        $participantLookup = [];
        $segments = [];
        $questions = [];
        $verbHits = [];

        $registerParticipant = static function (string $name) use (&$participants, &$participantLookup): void {
            $normalized = strtolower(trim($name));
            if ($normalized === '' || isset($participantLookup[$normalized])) {
                return;
            }
            $participantLookup[$normalized] = true;
            $participants[] = trim($name);
        };

        $lines = preg_split('/\r\n|\r|\n/', $text);
        if ($lines === false) {
            $lines = [$text];
        }
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (preg_match('/^\s*([A-Z][A-Za-z\s]{1,40}?):\s*(.+)$/', $line, $match) === 1) {
                $speaker = trim($match[1]);
                $utterance = trim($match[2]);
                if ($speaker !== '' && $utterance !== '') {
                    $segments[] = [
                        'speaker' => $speaker,
                        'utterance' => $this->ensureSentencePunctuation($utterance),
                    ];
                    $registerParticipant($speaker);
                }
            }
        }

        $dialogueVerbs = array_keys(self::$dialogueVerbs);
        $verbPattern = '/\b([A-Z][A-Za-z\-\']{1,40})\b\s+(?:' . implode('|', array_map('preg_quote', $dialogueVerbs)) . ')\s+["“]([^"“”]{3,})["”]/u';
        $matchCount = preg_match_all($verbPattern, $text, $matches, PREG_SET_ORDER);
        if ($matchCount !== false && $matchCount > 0) {
            foreach ($matches as $match) {
                $speaker = trim($match[1]);
                $quote = trim($match[2]);
                if ($speaker !== '' && $quote !== '') {
                    $segments[] = [
                        'speaker' => $speaker,
                        'utterance' => $this->ensureSentencePunctuation($quote),
                    ];
                    $registerParticipant($speaker);
                }
            }
        }

        $sentences = $this->extractSentences($text);
        foreach ($sentences as $sentence) {
            if (strpos($sentence, '?') !== false) {
                $questions[] = $sentence;
            }
        }

        $tokens = $this->tokenise($text);
        foreach ($tokens as $token) {
            if (isset(self::$dialogueVerbs[$token])) {
                $verbHits[$token] = true;
            }
        }

        return [
            'is_conversational' => $segments !== [] || $questions !== [] || $verbHits !== [],
            'participants' => array_slice($participants, 0, 8),
            'questions' => array_slice($this->uniqueStrings($questions), 0, 5),
            'dialogue_segments' => array_slice($segments, 0, 8),
            'narrative_verbs' => $this->mapToList($verbHits),
        ];
    }

    /**
     * @param array<int, array{token: string, count: int}> $keywords
     * @return array{focus: array<int, string>, contextual_highlights: array<int, array{topic: string, sentence: string}>}
     */
    private function buildTopicHighlights(string $text, array $keywords): array
    {
        $focusMap = [];
        foreach ($keywords as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $token = trim((string) ($entry['token'] ?? ''));
            if ($token === '') {
                continue;
            }
            $normalized = strtolower($token);
            if (isset($focusMap[$normalized])) {
                continue;
            }
            $focusMap[$normalized] = $token;
            if (count($focusMap) >= 8) {
                break;
            }
        }

        $focus = array_values($focusMap);
        $sentences = $this->extractSentences($text);
        $highlights = [];
        $usedSentences = [];

        foreach ($focus as $topic) {
            $topicLower = strtolower($topic);
            foreach ($sentences as $sentence) {
                $normalizedSentence = strtolower($sentence);
                if (strpos($normalizedSentence, $topicLower) === false) {
                    continue;
                }
                if (isset($usedSentences[$sentence])) {
                    continue;
                }
                $highlights[] = [
                    'topic' => $topic,
                    'sentence' => $sentence,
                ];
                $usedSentences[$sentence] = true;
                if (count($highlights) >= 6) {
                    break 2;
                }
                break;
            }
        }

        if ($highlights === [] && $sentences !== []) {
            foreach ($sentences as $sentence) {
                $highlights[] = [
                    'topic' => 'overview',
                    'sentence' => $sentence,
                ];
                if (count($highlights) >= 3) {
                    break;
                }
            }
            if ($focus === []) {
                $focus = ['overview'];
            }
        }

        return [
            'focus' => $focus,
            'contextual_highlights' => $highlights,
        ];
    }

    /**
     * @return array{certainty: array{score: float, tone: string, hedging_phrases: array<int, string>, assertive_phrases: array<int, string>}, emotive_language: array<int, string>}
     */
    private function evaluateNarrativeSignals(string $text): array
    {
        if ($text === '') {
            return [
                'certainty' => [
                    'score' => 0.5,
                    'tone' => 'balanced',
                    'hedging_phrases' => [],
                    'assertive_phrases' => [],
                ],
                'emotive_language' => [],
            ];
        }

        $lower = strtolower($text);
        $hedging = [];
        foreach (self::$speculativeVocabulary as $term => $flag) {
            if ($flag !== true) {
                continue;
            }
            if (preg_match('/\b' . preg_quote($term, '/') . '\b/u', $lower)) {
                $hedging[$term] = true;
            }
        }

        $assertive = [];
        foreach (self::$assertiveVocabulary as $term => $flag) {
            if ($flag !== true) {
                continue;
            }
            if (preg_match('/\b' . preg_quote($term, '/') . '\b/u', $lower)) {
                $assertive[$term] = true;
            }
        }

        $hedgingCount = count($hedging);
        $assertiveCount = count($assertive);
        $total = $hedgingCount + $assertiveCount;
        $score = 0.5;
        if ($total > 0) {
            $score = 0.5 + (($assertiveCount - $hedgingCount) / $total) * 0.5;
        }
        $score = $this->clamp($score, 0.0, 1.0);

        $tone = 'balanced';
        if ($score >= 0.65) {
            $tone = 'confident';
        } elseif ($score <= 0.35) {
            $tone = 'cautious';
        }

        $emotive = [];
        foreach (self::$emotiveVocabulary as $term => $flag) {
            if ($flag !== true) {
                continue;
            }
            if (preg_match('/\b' . preg_quote($term, '/') . '\b/u', $lower)) {
                $emotive[$term] = true;
            }
        }

        return [
            'certainty' => [
                'score' => round($score, 4),
                'tone' => $tone,
                'hedging_phrases' => $this->mapToList($hedging),
                'assertive_phrases' => $this->mapToList($assertive),
            ],
            'emotive_language' => $this->mapToList($emotive),
        ];
    }

    /**
     * @param array<int, array{token: string, count: int}> $keywords
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $factuality
     * @return array{
     *     readability: array{score: float, grade_level: float, reading_ease: float, label: string, reasons: array<int, string>},
     *     structure: array{score: float, paragraphs: int, transitions: int, reasons: array<int, string>},
     *     cohesion: array{score: float, overlap: float, keyword_coverage: float, reasons: array<int, string>},
     *     overall: array{score: float, label: string, reasons: array<int, string>}
     * }
     */
    private function evaluateWritingQuality(
        string $original,
        string $cleaned,
        array $keywords,
        array $intent,
        array $factuality
    ): array {
        $reference = $cleaned !== '' ? $cleaned : $original;
        if ($reference === '') {
            $defaultReasons = ['Insufficient text to evaluate writing quality.'];

            return [
                'readability' => [
                    'score' => 0.4,
                    'grade_level' => 12.0,
                    'reading_ease' => 50.0,
                    'label' => 'unclear',
                    'reasons' => $defaultReasons,
                ],
                'structure' => [
                    'score' => 0.4,
                    'paragraphs' => 0,
                    'transitions' => 0,
                    'reasons' => $defaultReasons,
                ],
                'cohesion' => [
                    'score' => 0.4,
                    'overlap' => 0.0,
                    'keyword_coverage' => 0.0,
                    'reasons' => $defaultReasons,
                ],
                'overall' => [
                    'score' => 0.4,
                    'label' => 'needs work',
                    'reasons' => $defaultReasons,
                ],
            ];
        }

        $readability = $this->profileReadability($reference);
        $structure = $this->assessStructure($reference);
        $cohesion = $this->assessCohesion($reference, $keywords);

        $factualityScore = isset($factuality['score']) && is_numeric($factuality['score'])
            ? $this->clamp((float) $factuality['score'])
            : 0.5;

        $intentPrimary = isset($intent['primary']) && is_string($intent['primary'])
            ? strtolower($intent['primary'])
            : 'informative';
        $intentConfidence = isset($intent['confidence']) && is_numeric($intent['confidence'])
            ? $this->clamp((float) $intent['confidence'])
            : 0.0;

        $overallReasons = array_merge($readability['reasons'], $structure['reasons'], $cohesion['reasons']);
        $misinformationPenalty = 0.0;

        if ($factualityScore < 0.45) {
            $misinformationPenalty += (0.45 - $factualityScore) * 0.6;
            $overallReasons[] = 'Claims feel speculative or weakly sourced, which harms credibility.';
        } elseif ($factualityScore >= 0.7) {
            $overallReasons[] = 'Evidence-backed statements support the overall argument.';
        }

        if ($intentPrimary === 'persuasive' && $factualityScore < 0.55) {
            $misinformationPenalty += 0.1;
            $overallReasons[] = 'Persuasive language lacks supporting evidence, reducing trust.';
        }

        if ($intentPrimary === 'informative' && $intentConfidence >= 0.5) {
            $overallReasons[] = 'Clear informative intent keeps the piece focused.';
        } elseif ($intentConfidence < 0.25) {
            $overallReasons[] = 'Intent is ambiguous, so the through-line is harder to follow.';
        }

        $baseScore = ($readability['score'] * 0.35) + ($structure['score'] * 0.35) + ($cohesion['score'] * 0.3);
        $overallScore = $this->clamp($baseScore - $misinformationPenalty);

        if ($factualityScore >= 0.8) {
            $overallScore = $this->clamp($overallScore + 0.05);
        }

        $label = 'needs work';
        if ($overallScore >= 0.75) {
            $label = 'polished';
        } elseif ($overallScore >= 0.6) {
            $label = 'strong';
        } elseif ($overallScore >= 0.45) {
            $label = 'developing';
        }

        return [
            'readability' => $readability,
            'structure' => $structure,
            'cohesion' => $cohesion,
            'overall' => [
                'score' => round($overallScore, 4),
                'label' => $label,
                'reasons' => $this->uniqueStrings($overallReasons),
            ],
        ];
    }

    /**
     * @return array{score: float, grade_level: float, reading_ease: float, label: string, reasons: array<int, string>}
     */
    private function profileReadability(string $text): array
    {
        $wordCount = max(1, $this->countWordsForQuality($text));
        $sentenceCount = max(1, $this->countSentencesForQuality($text));
        $syllableCount = max(1, $this->countSyllablesInText($text));

        $averageSentenceLength = $wordCount / $sentenceCount;
        $averageSyllables = $syllableCount / $wordCount;

        $readingEase = 206.835 - (1.015 * $averageSentenceLength) - (84.6 * $averageSyllables);
        $gradeLevel = (0.39 * $averageSentenceLength) + (11.8 * $averageSyllables) - 15.59;

        $normalisedScore = $this->clamp(($readingEase + 20) / 140);

        $label = 'challenging';
        if ($readingEase >= 70) {
            $label = 'clear';
        } elseif ($readingEase >= 60) {
            $label = 'accessible';
        } elseif ($readingEase >= 50) {
            $label = 'moderate';
        } elseif ($readingEase < 40) {
            $label = 'dense';
        }

        $reasons = [];
        if ($averageSentenceLength > 24) {
            $reasons[] = 'Sentences run long, which can burden readability.';
        } elseif ($averageSentenceLength < 10) {
            $reasons[] = 'Short sentences make the cadence choppy.';
        } else {
            $reasons[] = 'Sentence lengths feel balanced.';
        }

        if ($gradeLevel > 12) {
            $reasons[] = 'Vocabulary skews toward an advanced reading level.';
        } elseif ($gradeLevel <= 8) {
            $reasons[] = 'Approachable vocabulary keeps the text accessible.';
        }

        return [
            'score' => round($normalisedScore, 4),
            'grade_level' => round($gradeLevel, 1),
            'reading_ease' => round($readingEase, 1),
            'label' => $label,
            'reasons' => $this->uniqueStrings($reasons),
        ];
    }

    /**
     * @return array{score: float, paragraphs: int, transitions: int, reasons: array<int, string>}
     */
    private function assessStructure(string $text): array
    {
        $paragraphs = $this->splitParagraphs($text);
        $paragraphCount = count($paragraphs);
        $transitionCount = $this->countTransitionPhrases($text);

        $score = 0.3;
        $reasons = [];

        if ($paragraphCount >= 2) {
            $score += 0.3;
            $reasons[] = 'Multiple paragraphs provide breathing room for the reader.';
        } else {
            $reasons[] = 'Single-paragraph structure makes the piece feel compressed.';
        }

        if ($paragraphCount >= 3) {
            $score += 0.05;
        }

        if ($transitionCount >= 3) {
            $score += 0.2;
            $reasons[] = 'Frequent transition phrases guide the narrative.';
        } elseif ($transitionCount >= 1) {
            $score += 0.12;
            $reasons[] = 'Some transitions help signal shifts in topic.';
        } else {
            $reasons[] = 'Few transition phrases make idea shifts abrupt.';
        }

        if ($this->hasIntroductoryStatement($paragraphs)) {
            $score += 0.05;
            $reasons[] = 'Opening sentences frame the context clearly.';
        } else {
            $reasons[] = 'Introduction could better establish the context.';
        }

        if ($this->hasConcludingStatement($paragraphs)) {
            $score += 0.1;
            $reasons[] = 'Conclusion circles back to the core message.';
        } else {
            $reasons[] = 'Ending feels abrupt or disconnected from the opening.';
        }

        return [
            'score' => round($this->clamp($score), 4),
            'paragraphs' => $paragraphCount,
            'transitions' => $transitionCount,
            'reasons' => $this->uniqueStrings($reasons),
        ];
    }

    /**
     * @param array<int, array{token: string, count: int}> $keywords
     * @return array{score: float, overlap: float, keyword_coverage: float, reasons: array<int, string>}
     */
    private function assessCohesion(string $text, array $keywords): array
    {
        $paragraphs = $this->splitParagraphs($text);
        $firstParagraph = $paragraphs[0] ?? '';
        $lastParagraph = $paragraphs[count($paragraphs) - 1] ?? '';

        $firstTokens = $this->collectContentTokens($firstParagraph);
        $lastTokens = $this->collectContentTokens($lastParagraph);

        $combinedTokens = array_unique(array_merge($firstTokens, $lastTokens));
        $overlapTokens = array_intersect($firstTokens, $lastTokens);
        $overlapRatio = $combinedTokens === [] ? 0.5 : count($overlapTokens) / max(1, count($combinedTokens));

        $keywordTokens = [];
        foreach ($keywords as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $token = isset($entry['token']) ? strtolower((string) $entry['token']) : '';
            if ($token === '') {
                continue;
            }
            $keywordTokens[$token] = true;
        }

        $keywordMatches = 0;
        foreach (array_keys($keywordTokens) as $token) {
            if (stripos($text, $token) !== false) {
                $keywordMatches++;
            }
        }
        $keywordCoverage = $keywordTokens === []
            ? 0.6
            : $keywordMatches / max(1, count($keywordTokens));

        $progressionScore = $this->measureProgression($text);

        $score = $this->clamp((0.45 * $overlapRatio) + (0.35 * $keywordCoverage) + (0.2 * $progressionScore));

        $reasons = [];
        if ($overlapRatio >= 0.5) {
            $reasons[] = 'Key themes introduced early are revisited later.';
        } else {
            $reasons[] = 'Ending paragraphs introduce new ideas rather than resolving earlier ones.';
        }

        if ($keywordCoverage >= 0.6) {
            $reasons[] = 'Important keywords are woven throughout the piece.';
        } else {
            $reasons[] = 'Some focus topics appear only briefly, hurting follow-through.';
        }

        if ($progressionScore >= 0.6) {
            $reasons[] = 'Logical sequencing keeps the narrative on track.';
        } else {
            $reasons[] = 'Transitions between ideas could be smoother.';
        }

        return [
            'score' => round($score, 4),
            'overlap' => round($overlapRatio, 3),
            'keyword_coverage' => round($keywordCoverage, 3),
            'reasons' => $this->uniqueStrings($reasons),
        ];
    }

    /**
     * @param array<int, string> $paragraphs
     */
    private function hasIntroductoryStatement(array $paragraphs): bool
    {
        if ($paragraphs === []) {
            return false;
        }

        $first = strtolower($paragraphs[0]);
        foreach (self::$introductionSignals as $phrase) {
            if (strpos($first, $phrase) !== false) {
                return true;
            }
        }

        $firstSentenceWords = $this->countWordsForQuality($this->firstSentence($paragraphs[0]));

        return $firstSentenceWords >= 12;
    }

    /**
     * @param array<int, string> $paragraphs
     */
    private function hasConcludingStatement(array $paragraphs): bool
    {
        if ($paragraphs === []) {
            return false;
        }

        $lastParagraph = $paragraphs[count($paragraphs) - 1] ?? '';
        $last = strtolower($lastParagraph);
        foreach (self::$conclusionSignals as $phrase) {
            if (strpos($last, $phrase) !== false) {
                return true;
            }
        }

        $lastSentence = strtolower($this->lastSentence($lastParagraph));
        foreach (self::$conclusionSignals as $phrase) {
            if (strpos($lastSentence, $phrase) !== false) {
                return true;
            }
        }

        $lastTokens = $this->collectContentTokens($lastParagraph);

        return count($lastTokens) >= 4;
    }

    private function measureProgression(string $text): float
    {
        $lower = strtolower($text);
        $sequenceSignals = ['first', 'second', 'third', 'next', 'then', 'finally'];
        $hits = 0;
        foreach ($sequenceSignals as $signal) {
            if (strpos($lower, $signal) !== false) {
                $hits++;
            }
        }

        if ($hits >= 3) {
            return 0.85;
        }

        if ($hits === 2) {
            return 0.7;
        }

        if ($hits === 1) {
            return 0.55;
        }

        return 0.45;
    }

    /**
     * @return array{
     *     nouns: array<int, string>,
     *     verbs: array<int, string>,
     *     adjectives: array<int, string>,
     *     entity_associations: array<int, array{entity: string, adjectives: array<int, string>, verbs: array<int, string>}
     * }
     */
    private function extractGrammarInsights(string $text): array
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return [
                'nouns' => [],
                'verbs' => [],
                'adjectives' => [],
                'entity_associations' => [],
            ];
        }

        $sentences = $this->extractSentences($text);
        if ($sentences === []) {
            $sentences = [$trimmed];
        }

        $nounCounts = [];
        $nounDisplay = [];
        $verbCounts = [];
        $verbDisplay = [];
        $adjectiveCounts = [];
        $adjectiveDisplay = [];
        $entityMap = [];

        foreach ($sentences as $sentence) {
            $tokens = $this->tokeniseForGrammar($sentence);
            if ($tokens === []) {
                continue;
            }

            foreach ($tokens as $token) {
                $type = $token['type'];
                if ($type === 'noun') {
                    $this->registerTokenCount($nounCounts, $nounDisplay, $token['lower'], $token['original']);
                } elseif ($type === 'verb') {
                    $this->registerTokenCount($verbCounts, $verbDisplay, $token['lower'], $token['original']);
                } elseif ($type === 'adjective') {
                    $this->registerTokenCount($adjectiveCounts, $adjectiveDisplay, $token['lower'], $token['original']);
                }
            }

            $entities = $this->detectEntitiesFromTokens($tokens);
            if ($entities === []) {
                $entities = $this->fallbackEntitiesFromTokens($tokens);
            }

            foreach ($entities as $entity) {
                $label = trim((string) ($entity['label'] ?? ''));
                if ($label === '') {
                    continue;
                }

                $key = mb_strtolower($label, 'UTF-8');
                if (!isset($entityMap[$key])) {
                    $entityMap[$key] = [
                        'label' => $label,
                        'adjectives' => [],
                        'adjectiveDisplay' => [],
                        'verbs' => [],
                        'verbDisplay' => [],
                    ];
                }

                $start = (int) ($entity['start'] ?? 0);
                $end = (int) ($entity['end'] ?? $start);
                $contextStart = max(0, $start - 3);
                $contextEnd = min(count($tokens) - 1, $end + 4);

                for ($index = $contextStart; $index <= $contextEnd; $index++) {
                    $contextType = $tokens[$index]['type'];
                    if ($contextType === 'adjective') {
                        $this->registerTokenCount(
                            $entityMap[$key]['adjectives'],
                            $entityMap[$key]['adjectiveDisplay'],
                            $tokens[$index]['lower'],
                            $tokens[$index]['original']
                        );
                    } elseif ($contextType === 'verb') {
                        $this->registerTokenCount(
                            $entityMap[$key]['verbs'],
                            $entityMap[$key]['verbDisplay'],
                            $tokens[$index]['lower'],
                            $tokens[$index]['original']
                        );
                    }
                }
            }
        }

        $entityAssociations = [];
        foreach ($entityMap as $info) {
            $entityAssociations[] = [
                'entity' => $info['label'],
                'adjectives' => $this->reduceCounts($info['adjectives'], $info['adjectiveDisplay'], 5),
                'verbs' => $this->reduceCounts($info['verbs'], $info['verbDisplay'], 5),
            ];
        }

        usort(
            $entityAssociations,
            static function (array $left, array $right): int {
                return strcmp($left['entity'], $right['entity']);
            }
        );

        return [
            'nouns' => $this->reduceCounts($nounCounts, $nounDisplay, 15),
            'verbs' => $this->reduceCounts($verbCounts, $verbDisplay, 15),
            'adjectives' => $this->reduceCounts($adjectiveCounts, $adjectiveDisplay, 15),
            'entity_associations' => $entityAssociations,
        ];
    }

    /**
     * @return array<int, array{original: string, lower: string, type: string|null}>
     */
    private function tokeniseForGrammar(string $sentence): array
    {
        if ($sentence === '') {
            return [];
        }

        if (preg_match_all('/\\b[\p{L}][\p{L}\'’]*\\b/u', $sentence, $matches) === 0) {
            return [];
        }

        $tokens = [];
        foreach ($matches[0] as $word) {
            $lower = mb_strtolower($word, 'UTF-8');
            $tokens[] = [
                'original' => $word,
                'lower' => $lower,
                'type' => null,
            ];
        }

        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $previousLower = $tokens[$index - 1]['lower'] ?? null;
            $nextLower = $tokens[$index + 1]['lower'] ?? null;
            if ($this->looksLikeNounToken($tokens[$index]['original'], $tokens[$index]['lower'], $previousLower, $nextLower, $index === 0)) {
                $tokens[$index]['type'] = 'noun';
            }
        }

        for ($index = 0; $index < $count; $index++) {
            $previousLower = $tokens[$index - 1]['lower'] ?? null;
            $nextLower = $tokens[$index + 1]['lower'] ?? null;
            $previousOriginal = $tokens[$index - 1]['original'] ?? null;
            if ($this->looksLikeVerbTokenExtended($tokens[$index]['original'], $tokens[$index]['lower'], $previousLower, $nextLower, $previousOriginal)) {
                $tokens[$index]['type'] = 'verb';
            }
        }

        for ($index = 0; $index < $count; $index++) {
            $previousLower = $tokens[$index - 1]['lower'] ?? null;
            $nextLower = $tokens[$index + 1]['lower'] ?? null;
            if ($this->looksLikeAdjectiveToken($tokens[$index]['original'], $tokens[$index]['lower'], $previousLower, $nextLower, $tokens, $index)) {
                $tokens[$index]['type'] = 'adjective';
            }
        }

        return $tokens;
    }

    private function looksLikeNounToken(string $original, string $lower, ?string $previousLower, ?string $nextLower, bool $isSentenceStart): bool
    {
        if ($lower === '') {
            return false;
        }

        if (isset(self::$determiners[$lower])) {
            return false;
        }

        if (isset(self::$stopwords[$lower]) && !isset(self::$determiners[$lower])) {
            return false;
        }

        if (isset(self::$pronouns[$lower]) || isset(self::$verbForms[$lower]) || isset(self::$adjectiveIntensifiers[$lower])) {
            return false;
        }

        if ($this->looksLikeVerbTokenExtended($original, $lower, $previousLower, $nextLower)) {
            return false;
        }

        if (preg_match('/^\p{Lu}/u', $original) === 1) {
            return true;
        }

        if (preg_match('/^[A-Z0-9]{2,}$/', $original) === 1) {
            return true;
        }

        if ($previousLower !== null && isset(self::$determiners[$previousLower])) {
            return true;
        }

        $linkingVerbs = ['is', 'are', 'was', 'were', 'be', 'been', 'being', 'seems', 'seem', 'becomes', 'become', 'remains', 'remain', 'feels', 'feel', 'appears', 'appear', 'looks', 'look'];
        if ($previousLower !== null && in_array($previousLower, $linkingVerbs, true)) {
            return false;
        }

        foreach (self::$adjectiveSuffixes as $suffix) {
            if (str_ends_with($lower, $suffix) && strlen($lower) >= strlen($suffix) + 1) {
                return false;
            }
        }

        $nounSuffixes = [
            'tion',
            'sion',
            'ment',
            'ness',
            'ism',
            'ity',
            'ics',
            'ship',
            'hood',
            'ance',
            'ence',
            'ery',
            'ary',
            'ory',
            'dom',
            'ist',
            'er',
            'or',
            'ology',
            'logy',
            'age',
            'ture',
        ];

        foreach ($nounSuffixes as $suffix) {
            if (str_ends_with($lower, $suffix) && strlen($lower) >= strlen($suffix) + 2) {
                return true;
            }
        }

        if ($nextLower !== null && $nextLower === 'of') {
            return true;
        }

        if (!$isSentenceStart && $this->lexicon->contains($lower) && strlen($lower) >= 3) {
            return true;
        }

        return false;
    }

    private function looksLikeVerbTokenExtended(string $original, string $lower, ?string $previousLower, ?string $nextLower = null, ?string $previousOriginal = null): bool
    {
        if ($lower === '') {
            return false;
        }

        if (isset(self::$stopwords[$lower]) && !isset(self::$verbForms[$lower])) {
            return false;
        }

        if ($this->isLikelyVerbToken($lower)) {
            return true;
        }

        if ($previousLower !== null && ($previousLower === 'to' || isset(self::$verbForms[$previousLower]))) {
            return preg_match('/^[a-z]{3,}$/u', $lower) === 1;
        }

        if (preg_match('/^[a-z]{3,}(?:ed|ing|en)$/u', $lower) === 1) {
            return true;
        }

        if (preg_match('/^[a-z]{3,}(?:ise|ize|ify)$/u', $lower) === 1) {
            return true;
        }

        if (preg_match('/^[a-z]{3,}s$/u', $lower) === 1) {
            $stem = substr($lower, 0, -1);
            if ($stem !== '') {
                $subjectCandidate = false;
                if ($previousLower !== null && isset(self::$pronouns[$previousLower])) {
                    $subjectCandidate = true;
                }

                if ($previousOriginal !== null && preg_match('/^\p{Lu}/u', $previousOriginal) === 1) {
                    $subjectCandidate = true;
                }

                if (!$subjectCandidate && $previousLower !== null && !isset(self::$stopwords[$previousLower]) && !isset(self::$determiners[$previousLower])) {
                    if ($nextLower !== null) {
                        $prepositions = ['for', 'of', 'to', 'with', 'and', 'or', 'but', 'because', 'that'];
                        if (!in_array($nextLower, $prepositions, true)) {
                            $subjectCandidate = true;
                        }
                    }
                }

                if ($subjectCandidate) {
                    if ($this->isLikelyVerbToken($stem)) {
                        return true;
                    }

                    $stemEd = $stem . 'ed';
                    $stemIng = $stem . 'ing';
                    if ($this->lexicon->contains($stemEd) || $this->lexicon->contains($stemIng)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, array{original: string, lower: string, type: string|null}> $tokens
     */
    private function looksLikeAdjectiveToken(string $original, string $lower, ?string $previousLower, ?string $nextLower, array $tokens, int $index): bool
    {
        if ($lower === '') {
            return false;
        }

        if (preg_match('/^\p{Lu}/u', $original) === 1) {
            $nextToken = $tokens[$index + 1] ?? null;
            if ($index > 0 || ($nextToken !== null && preg_match('/^\p{Lu}/u', (string) ($nextToken['original'] ?? '')) === 1)) {
                return false;
            }
        }

        $nounLikeSuffixes = ['tion', 'sion', 'ment', 'ness', 'ism', 'ity', 'ics', 'ship', 'hood', 'ance', 'ence', 'dom', 'ology', 'logy', 'age', 'ture'];
        foreach ($nounLikeSuffixes as $suffix) {
            if (str_ends_with($lower, $suffix)) {
                return false;
            }
        }

        if (isset(self::$stopwords[$lower]) || isset(self::$pronouns[$lower]) || isset(self::$verbForms[$lower])) {
            return false;
        }

        if ($previousLower !== null && isset(self::$adjectiveIntensifiers[$previousLower])) {
            return true;
        }

        foreach (self::$adjectiveSuffixes as $suffix) {
            if (str_ends_with($lower, $suffix) && strlen($lower) >= strlen($suffix) + 1) {
                return true;
            }
        }

        $nextToken = $tokens[$index + 1] ?? null;
        if (is_array($nextToken) && ($nextToken['type'] ?? null) === 'noun') {
            return true;
        }

        if ($previousLower !== null && ($previousLower === 'more' || $previousLower === 'most')) {
            return true;
        }

        if ($nextLower !== null && isset(self::$determiners[$nextLower])) {
            return false;
        }

        return false;
    }

    /**
     * @param array<int, array{original: string, lower: string, type: string|null}> $tokens
     * @return array<int, array{label: string, start: int, end: int}>
     */
    private function detectEntitiesFromTokens(array $tokens): array
    {
        $entities = [];
        $buffer = [];
        $start = null;

        foreach ($tokens as $index => $token) {
            if ($this->isEntityToken($token)) {
                if ($buffer === []) {
                    $start = $index;
                }
                $buffer[] = $token['original'];
                continue;
            }

            if ($buffer !== [] && $this->isEntityConnector($token['lower'])) {
                $buffer[] = $token['original'];
                continue;
            }

            if ($buffer !== [] && $start !== null) {
                $entities[] = [
                    'label' => $this->normaliseEntityLabel($buffer),
                    'start' => $start,
                    'end' => $index - 1,
                ];
                $buffer = [];
                $start = null;
            }
        }

        if ($buffer !== [] && $start !== null) {
            $entities[] = [
                'label' => $this->normaliseEntityLabel($buffer),
                'start' => $start,
                'end' => count($tokens) - 1,
            ];
        }

        return array_values(array_filter($entities, static fn(array $entity): bool => $entity['label'] !== ''));
    }

    /**
     * @param array<int, array{original: string, lower: string, type: string|null}> $tokens
     * @return array<int, array{label: string, start: int, end: int}>
     */
    private function fallbackEntitiesFromTokens(array $tokens): array
    {
        $fallback = [];

        foreach ($tokens as $index => $token) {
            if (($token['type'] ?? null) !== 'noun') {
                continue;
            }

            $lower = $token['lower'];
            if ($lower === '' || isset(self::$pronouns[$lower]) || isset(self::$determiners[$lower])) {
                continue;
            }

            $label = trim($token['original']);
            if ($label === '') {
                continue;
            }

            $fallback[] = [
                'label' => $label,
                'start' => $index,
                'end' => $index,
            ];

            if (count($fallback) >= 2) {
                break;
            }
        }

        return $fallback;
    }

    /**
     * @param array{original: string, lower: string, type: string|null} $token
     */
    private function isEntityToken(array $token): bool
    {
        if (($token['type'] ?? null) !== 'noun') {
            return false;
        }

        $original = $token['original'];
        if ($original === '') {
            return false;
        }

        if (preg_match('/^\p{Lu}/u', $original) === 1) {
            return true;
        }

        if (preg_match('/^[A-Z0-9]{2,}$/', $original) === 1) {
            return true;
        }

        return false;
    }

    private function isEntityConnector(string $lower): bool
    {
        return in_array($lower, ['of', 'and', 'for', 'the', 'in'], true);
    }

    /**
     * @param array<int, string> $parts
     */
    private function normaliseEntityLabel(array $parts): string
    {
        $cleanParts = [];
        foreach ($parts as $part) {
            $trimmed = trim((string) $part);
            if ($trimmed !== '') {
                $cleanParts[] = $trimmed;
            }
        }

        while ($cleanParts !== []) {
            $first = mb_strtolower($cleanParts[0], 'UTF-8');
            if ($this->isEntityConnector($first)) {
                array_shift($cleanParts);
                continue;
            }
            break;
        }

        while ($cleanParts !== []) {
            $lastIndex = count($cleanParts) - 1;
            $last = mb_strtolower($cleanParts[$lastIndex], 'UTF-8');
            if ($this->isEntityConnector($last)) {
                array_pop($cleanParts);
                continue;
            }
            break;
        }

        if ($cleanParts === []) {
            return '';
        }

        $label = implode(' ', $cleanParts);
        $normalized = preg_replace('/\s+/', ' ', $label);
        if (!is_string($normalized)) {
            return trim($label);
        }

        return trim($normalized);
    }

    /**
     * @param array<string, int> $counts
     * @param array<string, string> $display
     * @return array<int, string>
     */
    private function reduceCounts(array $counts, array $display, int $limit): array
    {
        if ($counts === []) {
            return [];
        }

        arsort($counts);

        $results = [];
        foreach ($counts as $key => $count) {
            $value = trim((string) ($display[$key] ?? $key));
            if ($value === '') {
                continue;
            }
            $results[] = $value;
            if (count($results) >= $limit) {
                break;
            }
        }

        return $this->uniqueStrings($results);
    }

    /**
     * @param array<string, int> $counts
     * @param array<string, string> $display
     */
    private function registerTokenCount(array &$counts, array &$display, string $normalized, string $original): void
    {
        $normalized = trim($normalized);
        if ($normalized === '') {
            return;
        }

        if (mb_strlen($normalized, 'UTF-8') < 2) {
            return;
        }

        $counts[$normalized] = ($counts[$normalized] ?? 0) + 1;
        if (!isset($display[$normalized])) {
            $display[$normalized] = trim($original);
        }
    }


    private function splitParagraphs(string $text): array
    {
        $normalized = preg_replace('/\r\n|\r/', "\n", trim($text));
        if ($normalized === null || $normalized === '') {
            return [];
        }

        $paragraphs = $this->extractParagraphs($normalized, '/\n{2,}/');
        if (count($paragraphs) <= 1) {
            $fallback = $this->extractParagraphs($normalized, '/(?<=[.!?])\n+(?=\p{Lu})/u');
            if (count($fallback) > count($paragraphs)) {
                $paragraphs = $fallback;
            }
        }

        return $paragraphs;
    }

    /**
     * @param non-empty-string $pattern
     * @return array<int, string>
     */
    private function extractParagraphs(string $text, string $pattern): array
    {
        $parts = preg_split($pattern, $text);
        if ($parts === false) {
            return [trim($text)];
        }

        $paragraphs = [];
        foreach ($parts as $part) {
            $trimmed = trim($part);
            if ($trimmed === '') {
                continue;
            }

            $paragraphs[] = $trimmed;
        }

        return $paragraphs;
    }

    private function countTransitionPhrases(string $text): int
    {
        $lower = strtolower($text);
        $count = 0;
        foreach (self::$transitionSignals as $phrase) {
            $occurrences = substr_count($lower, $phrase);
            if ($occurrences > 0) {
                $count += $occurrences;
            }
        }

        return $count;
    }

    private function collectContentTokens(string $text, int $limit = 12): array
    {
        $tokens = $this->tokenise($text);
        if ($tokens === []) {
            return [];
        }

        $counts = [];
        foreach ($tokens as $token) {
            if (isset(self::$stopwords[$token])) {
                continue;
            }
            $counts[$token] = ($counts[$token] ?? 0) + 1;
        }

        arsort($counts);

        return array_slice(array_keys($counts), 0, $limit);
    }

    private function firstSentence(string $text): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($text));
        if ($sentences === false || $sentences === []) {
            return trim($text);
        }

        return (string) $sentences[0];
    }

    private function lastSentence(string $text): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($text));
        if ($sentences === false || $sentences === []) {
            return trim($text);
        }

        return (string) $sentences[count($sentences) - 1];
    }

    private function countWordsForQuality(string $text): int
    {
        $parts = preg_split('/\s+/u', trim($text));
        if ($parts === false) {
            return 0;
        }

        $count = 0;
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $count++;
        }

        return $count;
    }

    private function countSentencesForQuality(string $text): int
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($text));
        if ($sentences === false) {
            return 0;
        }

        $count = 0;
        foreach ($sentences as $sentence) {
            if (trim($sentence) === '') {
                continue;
            }
            $count++;
        }

        return $count;
    }

    private function countSyllablesInText(string $text): int
    {
        $tokens = $this->tokenise($text);
        if ($tokens === []) {
            return 0;
        }

        $count = 0;
        foreach ($tokens as $token) {
            $count += $this->countSyllablesInWord($token);
        }

        return $count;
    }

    private function countSyllablesInWord(string $word): int
    {
        $clean = strtolower(preg_replace('/[^a-z]/u', '', $word) ?? '');
        if ($clean === '') {
            return 1;
        }

        $clean = preg_replace('/e$/', '', $clean);
        if ($clean === null || $clean === '') {
            $clean = strtolower($word);
        }

        $matches = preg_match_all('/[aeiouy]+/', $clean);
        if ($matches === false) {
            return 1;
        }

        return max(1, $matches);
    }

    /**
     * @return array<int, string>
     */
    private function tokenise(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $lower = strtolower($text);
        $parts = preg_split('/[^a-z0-9]+/u', $lower);
        if ($parts === false) {
            return [];
        }

        $tokens = [];
        foreach ($parts as $part) {
            $trimmed = trim($part);
            if ($trimmed === '') {
                continue;
            }
            $tokens[] = $trimmed;
        }

        return $tokens;
    }

    /**
     * @return array<int, string>
     */
    private function extractSentences(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $text);
        if ($sentences === false) {
            $sentences = [$text];
        }

        $result = [];
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }
            $result[] = $this->ensureSentencePunctuation($sentence);
        }

        return $result;
    }

    /**
     * @param array<string, bool> $map
     * @return array<int, string>
     */
    private function mapToList(array $map): array
    {
        $values = [];
        foreach ($map as $value => $flag) {
            if ($flag !== true) {
                continue;
            }
            $trimmed = trim((string) $value);
            if ($trimmed === '') {
                continue;
            }
            $values[] = $trimmed;
        }

        $values = array_values(array_unique($values));
        sort($values, SORT_NATURAL | SORT_FLAG_CASE);

        return $values;
    }

    /**
     * @param array<int, string> $values
     * @return array<int, string>
     */
    private function uniqueStrings(array $values): array
    {
        $result = [];
        $seen = [];
        foreach ($values as $value) {
            $trimmed = trim((string) $value);
            if ($trimmed === '') {
                continue;
            }
            if (isset($seen[$trimmed])) {
                continue;
            }
            $seen[$trimmed] = true;
            $result[] = $trimmed;
        }

        return $result;
    }

    private function clamp(float $value, float $min = 0.0, float $max = 1.0): float
    {
        if ($value < $min) {
            return $min;
        }

        if ($value > $max) {
            return $max;
        }

        return $value;
    }

    private function ensureSentencePunctuation(string $sentence): string
    {
        $sentence = trim($sentence);
        if ($sentence === '') {
            return '';
        }

        if (preg_match('/[.!?]$/u', $sentence) === 1) {
            return $sentence;
        }

        return $sentence . '.';
    }

    /**
     * @param array<int, string> $items
     */
    private function formatList(array $items): string
    {
        $items = $this->uniqueStrings($items);
        $count = count($items);
        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return $items[0];
        }

        if ($count === 2) {
            return $items[0] . ' and ' . $items[1];
        }

        $last = array_pop($items);
        if ($last === null) {
            return '';
        }

        return implode(', ', $items) . ', and ' . $last;
    }

    /**
     * @param array<int, string> $sentences
     */
    private function findSentenceForEntity(array $sentences, string $entity): ?string
    {
        $needle = trim($entity);
        if ($needle === '') {
            return null;
        }

        foreach ($sentences as $sentence) {
            if (stripos($sentence, $needle) !== false) {
                return $this->ensureSentencePunctuation($sentence);
            }
        }

        return null;
    }

    private function formatKeywordForQuestion(string $keyword): string
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return '';
        }

        $firstChar = mb_substr($keyword, 0, 1);
        $rest = mb_substr($keyword, 1);

        return mb_convert_case($firstChar, MB_CASE_TITLE, 'UTF-8') . $rest;
    }

    private function capitaliseSentence(string $sentence, bool $ensurePunctuation): string
    {
        $sentence = trim($sentence);
        if ($sentence === '') {
            return '';
        }

        $sentence = mb_convert_case(mb_substr($sentence, 0, 1), MB_CASE_TITLE, 'UTF-8') . mb_substr($sentence, 1);

        if ($ensurePunctuation) {
            $lastChar = mb_substr($sentence, -1);
            if ($lastChar !== '.' && $lastChar !== '!' && $lastChar !== '?') {
                $sentence .= '.';
            }
        }

        return $sentence;
    }

    private function looksLikeName(string $token): bool
    {
        return preg_match('/^[a-z]+$/', $token) === 1 && strlen($token) >= 3;
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private function filterContextualLines(array $lines): array
    {
        $filtered = [];
        $previousWasBlank = true;
        $seen = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                if (!$previousWasBlank) {
                    $filtered[] = '';
                    $previousWasBlank = true;
                }
                continue;
            }

            if ($this->looksLikeBoilerplate($line)) {
                continue;
            }

            if ($this->looksLikeNavigationCluster($line)) {
                continue;
            }

            if ($this->looksLikeMeaninglessText($line)) {
                continue;
            }

            $signature = $this->lineSignature($line);
            if ($signature !== '' && isset($seen[$signature])) {
                continue;
            }

            if ($signature !== '') {
                $seen[$signature] = true;
            }

            $filtered[] = $line;
            $previousWasBlank = false;
        }

        return $filtered;
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private function removeUngrammaticalSentences(array $lines): array
    {
        $cleaned = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $cleaned[] = '';
                continue;
            }

            $leading = ltrim($line);
            if (preg_match('/^[-•·]+\s+/u', $leading) === 1) {
                $cleaned[] = $line;
                continue;
            }

            if (preg_match('/[.!?]/u', $line) !== 1) {
                $cleaned[] = $line;
                continue;
            }

            $sentences = preg_split('/(?<=[.!?])\s+/u', $line);
            if ($sentences === false) {
                $cleaned[] = $line;
                continue;
            }

            $validSentences = [];
            foreach ($sentences as $sentence) {
                $candidate = trim($sentence);
                if ($candidate === '') {
                    continue;
                }

                if ($this->isGrammaticallySoundSentence($candidate)) {
                    $validSentences[] = $candidate;
                }
            }

            if ($validSentences === []) {
                continue;
            }

            $cleaned[] = implode(' ', $validSentences);
        }

        return $cleaned;
    }

    private function looksLikeBoilerplate(string $line): bool
    {
        if ($line === '') {
            return true;
        }

        $normalized = strtolower(trim($line));
        if ($normalized === '') {
            return true;
        }

        foreach (self::$boilerplatePatterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        if (isset(self::$boilerplatePhrases[$normalized])) {
            return true;
        }

        $stripped = preg_replace('/[^a-z0-9\s]+/u', ' ', $normalized);
        if (!is_string($stripped)) {
            $stripped = $normalized;
        }

        $stripped = trim(preg_replace('/\s+/', ' ', $stripped) ?? $stripped);
        if ($stripped === '') {
            return true;
        }

        $tokens = explode(' ', $stripped);
        $tokenCount = 0;
        $boilerplateMatches = 0;
        $navigationMatches = 0;
        $categoryMatches = 0;
        $stopwordMatches = 0;
        $uniqueTokens = [];
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            $tokenCount++;
            if (isset(self::$boilerplateVocabulary[$token])) {
                $boilerplateMatches++;
            }
            if (isset(self::$navigationVocabulary[$token])) {
                $navigationMatches++;
            }
            if (isset(self::$stopwords[$token])) {
                $stopwordMatches++;
            }
            $uniqueTokens[$token] = true;
        }

        if ($tokenCount === 0) {
            return true;
        }

        $ratio = $boilerplateMatches / $tokenCount;

        if ($ratio >= 0.6 && $tokenCount <= 8) {
            return true;
        }

        if ($tokenCount <= 3 && $ratio >= 0.5) {
            return true;
        }

        if ($navigationMatches >= 2 && $navigationMatches >= ($tokenCount / 2)) {
            return true;
        }

        if (count($uniqueTokens) === 1 && $tokenCount <= 4) {
            return true;
        }

        $capitalizedTokenCount = 0;
        if (preg_match_all('/\b[A-Z][A-Za-z\']*\b/u', $line, $capitalizedMatches) > 0) {
            $capitalizedTokenCount = count($capitalizedMatches[0]);
        }

        if ($this->containsBasicSentenceStructure($line, $tokenCount, $stopwordMatches, $capitalizedTokenCount)) {
            return false;
        }

        return false;
    }

    private function looksLikeMeaninglessText(string $line): bool
    {
        $normalized = trim($line);
        if ($normalized === '') {
            return true;
        }

        $normalized = preg_replace('/^[\-*•·]+\s*/u', '', $normalized);
        if (!is_string($normalized)) {
            $normalized = trim($line);
        }

        if ($normalized === '') {
            return true;
        }

        if (preg_match('/^(?:published|updated)\s+(?:at\s+)?\d{1,2}:\d{2}(?:\s*[a-z]{2,4})?$/i', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^(?:published|updated)\s+\d{1,2}\s+(?:minute|minutes|hour|hours|day|days)\s+ago$/i', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^\d+s\s+ago$/i', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^\d{1,3}(?:,\d{3})*\s+(?:viewing|views)\b/i', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^\d{1,2}:\d{2}$/', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^revision\s+\d+/i', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^keywords?\s+added/i', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^[+-]?\d+(?:,\d{3})*(?:\.\d+)?\s+(?:characters|words)$/i', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^[a-z0-9.-]+\s+·\s*\d+(?:\.\d+)?$/i', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^(?:high|medium|low)\s*·\s*\d+(?:\.\d+)?$/i', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^scroll to (?:previous|next) item/i', $normalized) === 1) {
            return true;
        }

        if (preg_match('/[a-z]/i', $normalized) !== 1) {
            return true;
        }

        if (preg_match_all('/[A-Za-z][A-Za-z\']*/u', $normalized, $matches) === 0) {
            return true;
        }

        if (preg_match('/https?:\/\//i', $normalized) === 1) {
            return true;
        }

        $tokens = $matches[0] ?? [];
        $candidateCount = 0;
        $recognized = 0;
        $capitalized = 0;
        $allCapitalized = true;
        $stopwordCount = 0;
        $navigationMatches = 0;
        $categoryMatches = 0;
        $lowerTokens = [];

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            $candidateCount++;

            $lower = strtolower($token);
            if (isset(self::$stopwords[$lower])) {
                $stopwordCount++;
            }

            if (isset(self::$categoryVocabulary[$lower])) {
                $categoryMatches++;
            }
            if ($this->lexicon->contains($lower)) {
                $recognized++;
                continue;
            }

            if (preg_match('/^[A-Z][A-Za-z\']*$/u', $token) === 1 || preg_match('/^[A-Z]{2,}$/u', $token) === 1) {
                $capitalized++;
            } else {
                $allCapitalized = false;
            }

            if (isset(self::$navigationVocabulary[$lower])) {
                $navigationMatches++;
            }

            $lowerTokens[$lower] = true;
        }

        if ($candidateCount === 0) {
            return true;
        }

        if ($recognized > 0) {
            return false;
        }

        if (count($lowerTokens) === 1 && $candidateCount <= 4) {
            return true;
        }

        if ($navigationMatches >= 2 && $navigationMatches >= ($candidateCount / 2)) {
            return true;
        }

        if (
            $categoryMatches >= 2
            && $candidateCount > 0
            && ($categoryMatches / $candidateCount) >= 0.6
        ) {
            return true;
        }

        if ($candidateCount === $capitalized && $capitalized > 0) {
            return false;
        }

        if ($capitalized >= 2 && $allCapitalized) {
            return false;
        }

        if ($this->containsBasicSentenceStructure($normalized, $candidateCount, $stopwordCount, $capitalized)) {
            return false;
        }

        return true;
    }

    private function containsBasicSentenceStructure(string $line, int $tokenCount, int $stopwordCount, int $capitalizedTokenCount): bool
    {
        if ($tokenCount < 3) {
            return false;
        }

        $lowerLine = strtolower($line);
        $hasVerb = preg_match(
            '/\b(?:am|is|are|was|were|be|been|being|has|have|had|do|does|did|can|could|will|would|shall|should|may|might|must|[a-z]{3,}(?:ing|ed))\b/u',
            $lowerLine
        ) === 1;

        if (!$hasVerb) {
            return false;
        }

        if ($stopwordCount >= 2) {
            return true;
        }

        if ($stopwordCount >= 1 && $capitalizedTokenCount >= 1) {
            return true;
        }

        if (
            $stopwordCount >= 1
            && preg_match('/\b(?:[a-z]*[0-9][a-z0-9]*)\b/iu', $line) === 1
        ) {
            return true;
        }

        return false;
    }

    private function isGrammaticallySoundSentence(string $sentence): bool
    {
        $stripped = trim($sentence, " \t\n\r\0\x0B\"'“”‘’()[]{}<>");
        if ($stripped === '') {
            return false;
        }

        if (preg_match('/^\p{Lu}/u', $stripped) !== 1) {
            return false;
        }

        if (preg_match("/[.!?][\"'\\)\\]\\}»“”]*$/u", $stripped) !== 1) {
            return false;
        }

        if (preg_match_all('/\b[\p{L}\']+\b/u', $stripped, $wordMatches) < 2) {
            return false;
        }

        $lower = mb_strtolower($stripped, 'UTF-8');

        if (preg_match('/[^\x00-\x7F]/u', $stripped) === 1) {
            return true;
        }

        $tokens = $wordMatches[0] ?? [];

        if (!$this->containsVerbCandidate($lower, $tokens)) {
            return false;
        }

        if ($this->startsWithDependentClause($lower) && strpos($stripped, ',') === false) {
            return false;
        }

        if ($this->looksLikeRunOnSentence($stripped)) {
            return false;
        }

        $firstVerbIndex = null;
        foreach ($tokens as $index => $token) {
            $lowerToken = mb_strtolower($token, 'UTF-8');
            if ($firstVerbIndex === null && $this->isLikelyVerbToken($lowerToken)) {
                $firstVerbIndex = $index;
            }
        }

        if ($firstVerbIndex === null) {
            return false;
        }

        $subjectFound = false;
        foreach ($tokens as $index => $token) {
            if ($index >= $firstVerbIndex) {
                break;
            }

            $lowerToken = mb_strtolower($token, 'UTF-8');
            if ($this->tokenIsSubjectCandidate($token, $lowerToken)) {
                $subjectFound = true;
                break;
            }
        }

        if ($subjectFound) {
            return true;
        }

        return false;
    }

    private function isLikelyVerbToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        if (isset(self::$verbForms[$token])) {
            return true;
        }

        return preg_match('/^[a-z]{3,}(?:ed|ing|en|es)$/u', $token) === 1;
    }

    /**
     * @param array<int, string> $tokens
     */
    private function containsVerbCandidate(string $lowerSentence, array $tokens): bool
    {
        if (
            preg_match(
                '/\b(?:am|is|are|was|were|be|been|being|has|have|had|do|does|did|can|could|will|would|shall|should|may|might|must|need|needs|needed|seem|seems|seemed|seeming)\b/u',
                $lowerSentence
            ) === 1
        ) {
            return true;
        }

        foreach ($tokens as $token) {
            $lowerToken = mb_strtolower($token, 'UTF-8');
            if ($this->isLikelyVerbToken($lowerToken)) {
                return true;
            }
        }

        return false;
    }

    private function tokenIsSubjectCandidate(string $token, string $lowerToken): bool
    {
        if (isset(self::$pronouns[$lowerToken])) {
            return true;
        }

        if (isset(self::$stopwords[$lowerToken]) || isset(self::$nonSubjectVocabulary[$lowerToken])) {
            return false;
        }

        if ($this->isLikelyVerbToken($lowerToken)) {
            return false;
        }

        if ($this->lexicon->contains($lowerToken)) {
            return true;
        }

        return preg_match('/^[A-Z][\p{L}\']*/u', $token) === 1;
    }

    private function looksLikeClauseSubject(string $token, string $lowerToken): bool
    {
        if (isset(self::$clausePronouns[$lowerToken])) {
            return true;
        }

        return preg_match('/^[A-Z][\p{L}\']*/u', $token) === 1;
    }

    private function startsWithDependentClause(string $lowerSentence): bool
    {
        foreach ([
            'because',
            'although',
            'though',
            'while',
            'since',
            'unless',
            'until',
            'if',
            'when',
            'whenever',
            'whereas',
            'wherever',
            'after',
            'before',
        ] as $starter) {
            if (str_starts_with($lowerSentence, $starter . ' ')) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeRunOnSentence(string $sentence): bool
    {
        if (strpos($sentence, ',') !== false || strpos($sentence, ';') !== false) {
            return false;
        }

        $lower = mb_strtolower($sentence, 'UTF-8');
        if (preg_match('/\b(?:and|but|or|so|yet|for|nor|because|although|while|since|after|before|though|however|therefore)\b/u', $lower) === 1) {
            return false;
        }

        if (preg_match_all('/\b[\p{L}\']+\b/u', $sentence, $matches) < 2) {
            return false;
        }

        $words = $matches[0];
        $subjectVerbPairs = 0;
        $hasSubject = false;

        foreach ($words as $word) {
            $lowerWord = mb_strtolower($word, 'UTF-8');

            if (!$hasSubject && $this->looksLikeClauseSubject($word, $lowerWord)) {
                $hasSubject = true;
                continue;
            }

            if ($hasSubject && $this->isLikelyVerbToken($lowerWord)) {
                $subjectVerbPairs++;
                $hasSubject = false;
            }
        }

        return $subjectVerbPairs >= 2;
    }

    private function looksLikeNavigationCluster(string $line): bool
    {
        $normalized = strtolower(trim($line));
        if ($normalized === '') {
            return true;
        }

        if (preg_match('/\d/', $normalized) === 1) {
            return false;
        }

        if (preg_match('/[.!?]/', $normalized) === 1) {
            return false;
        }

        $tokens = preg_split('/\s+/u', $normalized);
        if ($tokens === false) {
            $tokens = [$normalized];
        }

        $tokenCount = 0;
        $navigationMatches = 0;
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            $tokenCount++;
            if (isset(self::$navigationVocabulary[$token])) {
                $navigationMatches++;
            }
        }

        if ($tokenCount === 0) {
            return true;
        }

        if ($navigationMatches >= 2 && $navigationMatches >= ($tokenCount / 2)) {
            return true;
        }

        if ($tokenCount <= 5 && $navigationMatches >= 2) {
            return true;
        }

        return false;
    }

    private function lineSignature(string $line): string
    {
        $normalized = strtolower(trim(preg_replace('/\s+/u', ' ', $line) ?? ''));
        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', '', $normalized);
        if (!is_string($normalized)) {
            return '';
        }

        if (strlen($normalized) > 48) {
            return '';
        }

        return $normalized;
    }

    private function preNormalizeDocument(string $text): string
    {
        $text = str_replace(["\u{00A0}", "\u{200B}", "\u{200C}", "\u{200D}"], ' ', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = preg_replace(
            '/<\s*(?:script|style|noscript|template)\b[^>]*>.*?<\s*\/\s*(?:script|style|noscript|template)\s*>/isu',
            ' ',
            $text
        );
        $text = preg_replace(
            '/<\s*(?:nav|aside|menu)\b[^>]*>.*?<\s*\/\s*(?:nav|aside|menu)\s*>/isu',
            "\n",
            is_string($text) ? $text : ''
        );
        $text = preg_replace(
            '/<\s*(?:footer|header)\b[^>]*>.*?<\s*\/\s*(?:footer|header)\s*>/isu',
            "\n",
            is_string($text) ? $text : ''
        );
        $text = is_string($text) ? $text : '';

        $text = preg_replace('/<\s*br\s*\/?>/iu', "\n", $text);
        $text = preg_replace('/<\s*\/p\s*>/iu', "\n\n", $text);
        $text = preg_replace('/<\s*(?:div|section|article|header|footer|nav|main|aside)\b[^>]*>/iu', "\n", $text);
        $text = preg_replace('/<\s*\/\s*(?:div|section|article|header|footer|nav|main|aside)\s*>/iu', "\n", $text);
        $text = preg_replace('/<\s*h[1-6]\b[^>]*>/iu', "\n", $text);
        $text = preg_replace('/<\s*\/h[1-6]\s*>/iu', "\n", $text);
        $text = preg_replace('/<\s*li\b[^>]*>/iu', "\n- ", $text);
        $text = preg_replace('/<\s*\/li\s*>/iu', "\n", $text);
        $text = preg_replace('/<\s*(?:ul|ol)\b[^>]*>/iu', "\n", $text);
        $text = preg_replace('/<\s*\/(?:ul|ol)\s*>/iu', "\n", $text);
        $text = preg_replace('/<\s*\/?(?:span|strong|em|b|i|u|small|sup|sub)\b[^>]*>/iu', ' ', $text);

        // Ensure inline tags that are stripped later do not glue neighbouring words together.
        $text = preg_replace('/>(?=\S)/u', '> ', is_string($text) ? $text : '');
        $text = preg_replace('/(?<=\S)</u', ' <', is_string($text) ? $text : '');

        $text = strip_tags(is_string($text) ? $text : '');
        $text = is_string($text) ? $text : '';

        $text = preg_replace('/\r\n?/', "\n", $text);
        $text = is_string($text) ? $text : '';

        $text = preg_replace('/[^\S\n]+/u', ' ', $text);
        $text = is_string($text) ? $text : '';

        $text = preg_replace('/\s*\n\s*/u', "\n", $text);
        $text = is_string($text) ? $text : '';

        $text = preg_replace('/\n{3,}/u', "\n\n", $text);
        $text = is_string($text) ? $text : '';

        return trim($text);
    }
}
