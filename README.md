# Mail Merge & Letter Creation REDCap External Module (`mailmerge_v1.0.0`)

An automated Word document (`.docx`) mail merge, template management, and letter generation engine for REDCap.

## Features

- **Unified 3-Tab Interface (`index.php`):**
  - **Queue:** Evaluates rules against study records, displays participants with letters due, and enables single or batch generation.
  - **Template Library:** Compact multi-file drag-and-drop uploader (`.docx`), automatic merge tag inspection, and template catalog.
  - **Configuration:** Letter rules, longitudinal target event selection, trigger conditions, flag field tracking (with date/time vs. binary `1` auto-detection), and merge tag mappings.
- **Dedicated File Repository "MailMerge" Folder:**
  - All uploaded `.docx` templates and generated participant letters are automatically organized into a dedicated **`MailMerge`** folder in the project's File Repository.
- **Automated Daily Cron:**
  - Evaluates active rules daily and automatically processes pending letters.
