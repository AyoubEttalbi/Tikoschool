{{--
    The absence notice sent to a guardian over WhatsApp.

    This lived as a PHP heredoc inside AttendanceController, duplicated VERBATIM in two
    methods — the bulk attendance save and the manual "notify" button. Two copies of a
    25-line Arabic block meant every wording change had to be made twice, and no reviewer
    could tell whether the copies had drifted.

    Deliberately a Blade file rather than a notification_templates table: there is no admin
    UI to edit a template, so a table would be a config store edited with raw SQL — and a
    file is diffable, greppable and reviewable, which a TEXT column is not.

    ── BIDIRECTIONAL TEXT ────────────────────────────────────────────────────────────────

    This is Arabic (right-to-left) with Latin names, subjects, URLs and digits embedded in
    it, and that mixture is the single most common way a message like this comes out
    mangled on a real phone. Left alone, Unicode's bidi algorithm reorders the neutral
    characters around each Latin run: a sentence ending "... بمركز Tiko School." shows the
    full stop on the WRONG SIDE, and a phone number written 08 08 50 47 94 can appear with
    its groups in a different order than it was typed — which is worse than ugly, because a
    parent may dial it.

    Two invisible controls fix it, and both are load-bearing:

      $rtl   U+200F RIGHT-TO-LEFT MARK, at the start of every line that is Arabic. It pins
             the line's base direction to RTL even when the line opens with an emoji or a
             digit, which are neutral and would otherwise let the first Latin word decide.

      $ltr   U+2068 FIRST STRONG ISOLATE … U+2069 POP DIRECTIONAL ISOLATE, around every
             interpolated value. It seals the value into its own run so its direction —
             whichever it turns out to be — cannot leak into the Arabic around it.
             "Isolate" rather than "embed": embedding still lets neutrals at the edges join
             the surrounding run, which is exactly the misplaced-full-stop bug.

    They are invisible. If a client ignores them nothing is displayed wrong; the ordering
    simply falls back to what it was before.

    -- {!! !!} AND NOT {{ }} --------------------------------------------------------------

    Every value below is printed RAW, and that is deliberate.

    This is a plain-text WhatsApp message. It is never parsed as HTML, so Blade's default
    escaping is not protection here -- it is corruption. It turns `&` into `&amp;`, which
    broke the Instagram link (`?igsh=...&amp;utm_source=qr`), and it turns an apostrophe
    into `&#039;`, so a guardian would have read that their child "O&#039;Brien" was absent.

    What actually protects this message is OutboundMessageService::plain(), which strips
    the characters WhatsApp itself treats as formatting (* _ ~ `) from anything a human
    typed. HTML escaping never had a job to do.

    Whitespace is significant. WhatsApp renders *asterisks* as bold and preserves these
    line breaks exactly as they appear here.
--}}
🏫 *{!! $schoolName !!}* 🌟

{!! $rtl !!}السلام عليكم ورحمة الله وبركاته،

{!! $rtl !!}📋 *تنبيه غياب الطالب*
{!! $rtl !!}نخبركم أن {!! $pronoun !!} *{!! $studentName !!}* قد {!! $verb !!} عن حصة *{!! $subject !!}* التي جرت يوم *{!! $date !!}* بمركز {!! $schoolName !!}.

{!! $rtl !!}👨‍🏫 *المعلم:* {!! $teacherName !!}
{!! $rtl !!}📅 *الفصل:* {!! $className !!}

{!! $rtl !!}📞 *للاستفسار والتواصل:*
@if($schoolPhone)
{!! $rtl !!}📱 {!! $schoolPhone !!}
@endif
@if($schoolInstagram)
{!! $rtl !!}📷 {!! $schoolInstagram !!}
@endif

{!! $rtl !!}🕐 *ساعات العمل:*
{!! $rtl !!}{!! $schoolHours !!}

{!! $rtl !!}نرجو منكم التفضل بالتواصل معنا لتوضيح سبب الغياب، حتى نتمكن من متابعة مستواه وضمان استفادته الكاملة من الدروس.

{!! $rtl !!}شكراً لتعاونكم 🌷
{!! $rtl !!}*إدارة {!! $schoolName !!}*
