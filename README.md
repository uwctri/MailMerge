# MailMerge - REDCap External Module

An automated Word document (`.docx`) mail merge, template management, and letter generation engine for REDCap.

## Features

- **Unified 3-Tab Interface (`index.php`):**
  - **Queue:** Evaluates rules against study records, displays participants with letters due, and enables single or batch generation. Distinguishes between "No Letter Rules Configured" and "All Caught Up".
  - **Template Library:** Dedicated, spacious multi-file drag-and-drop uploader (`.docx`), automatic merge tag inspection, and template catalog.
  - **Configuration:** 
    - **Automation & Storage Settings:** Select between holding letters in queue for manual review, automated File Repository archiving, or server filesystem output (gated by a system-level setting).
    - **Custom File Repository Locations:** Customize the target folder names for both uploaded templates and generated letters.
    - **Letter Rules & Logic:** Set target events, trigger conditions, flag field tracking (date/time vs. binary `1`), configurable file naming schemes (`{record}_{letter_name}`), and merge tag mappings.
- **Custom Tooltips:** Non-flickering hover boxes for inline contextual guidance.
- **Automated Daily Cron:** Evaluates active rules daily and automatically processes pending letters according to the selected delivery mode.
