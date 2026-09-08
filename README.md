# MVP Project — User & Developer Guide

## Overview

This project is a task management system with:

- A Laravel-based admin panel
- Staff and task management
- Telegram bot integration
- Task assignment through the admin panel and bot
- Task notifications with interactive Telegram buttons
- Support for text, photos, videos, files, and mixed attachments
- Task acceptance, return, and status management

The system is intended to support different roles in the task lifecycle:

1. **Developer** — maintains and extends the system.
2. **Admin** — manages the organization, staff, permissions, and tasks.
3. **Assignor** — creates and assigns tasks to staff members.
4. **Assignee** — receives, accepts, works on, and completes assigned tasks.

---

# 1. Roles and Task Lifecycle

## Main Roles

### Developer

Responsible for:

- Installing and configuring the project
- Maintaining the Laravel application
- Maintaining the Telegram bot integration
- Managing database migrations and configuration
- Debugging task and notification flows
- Extending project functionality

### Admin

Responsible for:

- Managing staff
- Managing permissions and access
- Reviewing tasks
- Monitoring task statuses and priorities
- Managing the administrative side of the system

### Assignor

A user who creates and assigns tasks.

Typical actions:

- Create a task
- Select an assignee
- Set task priority
- Add a description
- Attach media or files
- Send the task
- Track progress
- Review returned tasks

### Assignee

A staff member who receives and performs tasks.

Typical actions:

- Receive a Telegram notification
- Review task information
- Accept the task
- Return the task when necessary
- Update task progress/status
- Complete the assigned work

---

# 2. Task Lifecycle

A typical task follows this workflow:

```text
Task Created
      |
      v
Task Assigned
      |
      v
Telegram Notification Sent
      |
      +----------------------------+
      |                            |
      v                            v
Task Accepted                 Task Returned
      |                            |
      v                            v
Work Started                Assignor Reviews
      |                            |
      v                            |
Task Progress Updated <------------+
      |
      v
Task Completed
```

The exact available statuses depend on the application's current status configuration.

In the admin panel, task statuses are displayed using Uzbek labels and visual status colors.

---

# 3. Developer Guide

## 3.1 Project Requirements

Before running the project, install the required software.

Typical requirements:

- PHP
- Composer
- Laravel-compatible web server
- MySQL or another configured database
- Node.js and npm, if frontend assets are compiled
- Telegram Bot token
- Queue worker, if notifications are queued

Check the project's `composer.json`, `.env.example`, and frontend configuration files for the exact versions required by the current project.

---

## 3.2 Installation

Clone the project and install dependencies:

```bash
git clone <repository-url>
cd <project-directory>

composer install
npm install
```

Create the environment file:

```bash
cp .env.example .env
```

Generate the Laravel application key:

```bash
php artisan key:generate
```

Configure the database and application environment in `.env`.

Run migrations:

```bash
php artisan migrate
```

If the project contains seeders required for initial permissions, users, or staff data:

```bash
php artisan db:seed
```

Compile frontend assets when required:

```bash
npm run dev
```

For production builds:

```bash
npm run build
```

---

## 3.3 Environment Configuration

Configure the following important values in `.env`.

### Application

```env
APP_NAME="MVP Project"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost
```

### Database

Configure the database connection according to the environment:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

### Telegram

Configure the Telegram bot credentials required by the application's Telegram integration.

The exact environment variable names should match the project's current Telegram configuration.

Never commit the following to source control:

- Bot tokens
- API secrets
- Database passwords
- Production credentials

---

## 3.4 Running the Application

Start the Laravel development server:

```bash
php artisan serve
```

If the project uses Vite:

```bash
npm run dev
```

If queues are used for notifications or background processing:

```bash
php artisan queue:work
```

Use the queue configuration defined in the project's environment.

---

## 3.5 Database Changes

When changing models or database structure:

1. Create a migration.
2. Update the related model.
3. Update relationships if required.
4. Update validation and business logic.
5. Update affected admin panel views.
6. Test Telegram and notification flows when task-related data changes.

Example:

```bash
php artisan make:migration add_example_column_to_tasks_table
```

Run migrations:

```bash
php artisan migrate
```

Do not manually change production database schemas without a migration unless there is an operational reason and a documented recovery plan.

---

## 3.6 Important Development Areas

The project contains several major areas.

### Admin Panel

Responsible for:

- Staff management
- Task listing
- Task details
- Task creation and editing
- Status and priority visualization
- Permission-controlled access

Frontend pages are implemented as Laravel Blade templates.

When modifying pages:

- Keep the existing frontend design unless a redesign is explicitly required.
- Reuse shared components where possible.
- Pass real data from controllers.
- Avoid leaving placeholder or raw static data in production views.

### Task Management

Task logic includes:

- Creating tasks
- Assigning staff
- Status management
- Priority management
- Task details
- Attachments
- Notifications

Before changing task logic, verify all affected flows:

- Admin-created tasks
- Bot-created tasks
- Telegram notifications
- Task acceptance
- Task return
- Status updates
- Task detail navigation

### Telegram Bot

The Telegram integration handles interactions between users and the task system.

Important responsibilities include:

- Identifying Telegram users
- Resolving staff members
- Creating tasks
- Assigning tasks
- Sending notifications
- Handling callback buttons
- Processing media and files

Do not change the entire bot pipeline when fixing a localized issue. Prefer targeted changes that preserve existing working behavior.

---

# 4. Telegram Bot Usage

## 4.1 Staff Identification

The bot resolves Telegram users to staff members registered in the system.

For reliable operation:

- The staff member must exist in the database.
- The Telegram account should be linked or authenticated according to the project's authentication flow.
- The staff member must have the necessary permissions.

---

## 4.2 Creating Tasks Through the Bot

The bot can process natural-language task assignment requests.

For example:

```text
Xurshid akaga ertaga hisobotni tayyorlash vazifasini bering.
```

The system should:

1. Understand the intended task.
2. Resolve the staff member.
3. Create the task.
4. Save task data.
5. Send the notification to the assignee.

### Duplicate Staff Names

If multiple staff members have the same or matching name, the bot must not silently choose the wrong person.

Instead, it should ask the user which staff member is intended.

The user should then select or clarify the correct staff member before task assignment continues.

---

## 4.3 Telegram Media Handling

The system supports task-related attachments.

Supported scenarios include:

### No Media

Send one notification message containing the task notification text and interactive buttons.

### One Photo or Video

Send the media with the notification text as the caption.

The task action buttons must remain available.

### Multiple Photos or Videos

Send them as a Telegram media group.

The notification text should be attached as the caption of the first media item.

Task action buttons must still be available through the appropriate notification message.

### Mixed Files and Media

For mixed attachments:

1. Send the task notification first.
2. Send each attachment according to its type.

This preserves a clear notification message and task interaction buttons.

---

## 4.4 Telegram Task Buttons

Task notifications must include interactive action buttons.

The primary buttons include:

- **Qabul qilish** — Accept the task
- **Qaytarish** — Return the task

The buttons trigger callback handlers that update the task workflow.

When modifying notification delivery, always verify that buttons are included.

A media notification that successfully sends but has no task action buttons is incomplete from a user workflow perspective.

---

# 5. Admin Guide

## 5.1 Accessing the Admin Panel

The admin panel provides centralized management of:

- Staff
- Tasks
- Task statuses
- Task priorities
- Permissions
- System users

Access requires appropriate authentication and permissions.

---

## 5.2 Staff Management

Administrators can manage staff records.

Typical staff information may include:

- Name
- Contact information
- Position
- Permissions
- Telegram-related identification
- Other organizational details

When creating staff records, ensure names and identifying information are accurate because the Telegram bot may use this information to resolve assignees.

---

## 5.3 Viewing Staff Information

The staff detail page can show information related to the staff member and their tasks.

Task statuses should be visually distinguishable using colored status indicators.

This allows administrators to quickly understand:

- New tasks
- Tasks in progress
- Accepted tasks
- Returned tasks
- Completed tasks

The exact colors are part of the current frontend implementation and should remain consistent across relevant pages.

---

## 5.4 Managing Tasks

The tasks index page displays tasks in a tabular format.

The table should allow administrators and authorized users to:

- View tasks
- Identify the assignee
- Review status
- Review priority
- Open a task
- Navigate to task details

### Status and Priority Display

Task statuses and priorities should be visually meaningful.

Requirements:

- Status names should be displayed in Uzbek in the interface.
- Status indicators should use appropriate colors.
- Priority indicators should also use colors.
- Visual styling should be consistent with the staff task table and other existing task displays.

### Opening a Task

Clicking a task from the tasks index table should open the corresponding task detail page.

If this behavior stops working:

1. Check the current Blade markup.
2. Check row and link targets.
3. Check JavaScript event handlers.
4. Check whether another element prevents click propagation.
5. Check route generation and task identifiers.

Do not assume the backend is the problem before checking the current frontend event flow.

---

# 6. Assignor Guide

## 6.1 Creating a Task

An assignor creates a task by providing the necessary task information.

Typical information includes:

- Task title
- Task description
- Assignee
- Priority
- Deadline, when applicable
- Attachments

The task can be created through the admin panel or Telegram bot, depending on the user's permissions and available workflow.

---

## 6.2 Selecting an Assignee

Choose the correct staff member carefully.

If the system finds multiple staff members with the same name:

- Do not assume the first result is correct.
- Confirm which staff member should receive the task.

This is especially important when assigning tasks through natural-language bot commands.

---

## 6.3 Adding Attachments

Tasks may contain:

- Photos
- Videos
- Documents
- Other supported files

For Telegram delivery, the system handles attachments according to their type and quantity.

The assignor should verify that important files are included before submitting the task.

---

## 6.4 Monitoring Task Progress

After assigning a task, monitor its status.

Possible workflow actions include:

- Waiting for acceptance
- Accepted
- In progress
- Returned
- Completed

Use the admin panel to review tasks and investigate returned or delayed assignments.

---

# 7. Assignee Guide

## 7.1 Receiving a Task

The assignee receives a Telegram notification when a task is assigned.

The notification may contain:

- Task information
- Description
- Attachments
- Interactive buttons

The primary available actions are:

- **Qabul qilish**
- **Qaytarish**

---

## 7.2 Accepting a Task

Press:

```text
Qabul qilish
```

when you are ready to accept responsibility for the task.

The system updates the task workflow accordingly.

Only accept a task when:

- The task is understandable.
- Required information is available.
- You can perform the assignment.

---

## 7.3 Returning a Task

Press:

```text
Qaytarish
```

when the task cannot be accepted or requires clarification.

Typical reasons may include:

- Incorrect assignee
- Missing information
- Missing files
- Unclear requirements
- Scheduling conflicts
- Another workflow issue

The task is then returned to the appropriate workflow state for review.

---

## 7.4 Working on the Task

After accepting a task:

1. Review the requirements.
2. Review all attachments.
3. Perform the required work.
4. Update task progress when the system workflow provides that option.
5. Complete the task according to organizational procedures.

---

# 8. Permissions

The project uses permission-based access control.

Users should only receive permissions required for their responsibilities.

Typical permission areas include:

- Viewing tasks
- Creating tasks
- Assigning tasks
- Managing staff
- Managing users
- Accessing administrative functions

When adding a new feature:

1. Determine who should access it.
2. Add or reuse the appropriate permission.
3. Protect routes and actions.
4. Verify unauthorized users cannot access the feature through direct URLs or API requests.

Do not rely only on hiding frontend buttons for authorization.

Authorization must also be enforced server-side.

---

# 9. Development and Testing Checklist

Before deploying changes, test the affected workflow.

## Task Creation

- [ ] Create a task through the admin panel.
- [ ] Create a task through the Telegram bot, when applicable.
- [ ] Verify the correct assignee is selected.
- [ ] Test duplicate staff names.
- [ ] Verify priority and status values.

## Task Display

- [ ] Verify the task appears in the tasks index.
- [ ] Verify status labels are Uzbek.
- [ ] Verify status colors are correct.
- [ ] Verify priority colors are correct.
- [ ] Verify clicking a task opens its detail page.

## Telegram Notifications

- [ ] Test a task with no attachments.
- [ ] Test a task with one photo.
- [ ] Test a task with one video.
- [ ] Test multiple photos.
- [ ] Test multiple videos.
- [ ] Test mixed media and files.
- [ ] Verify notification text.
- [ ] Verify **Qabul qilish** button.
- [ ] Verify **Qaytarish** button.

## Callback Actions

- [ ] Accept a task.
- [ ] Return a task.
- [ ] Verify the task status changes correctly.
- [ ] Verify invalid or duplicate callback actions are handled safely.

---

# 10. Debugging Guide

## Task Does Not Open From Tasks Index

Check:

1. Blade table markup.
2. Generated task URLs.
3. JavaScript click handlers.
4. Event propagation.
5. Overlaying elements.
6. Route parameters.
7. Browser console errors.

Review the current implementation before replacing working code.

---

## Notification Has No Buttons

Check:

- The Telegram inline keyboard generation.
- Callback data.
- The notification sending method.
- Media-specific sending branches.

A common issue is that media delivery logic changes the message-sending path and accidentally omits the reply markup containing task buttons.

Always test both:

- Text-only tasks
- Tasks with attachments

---

## Wrong Staff Member Is Selected

Check the staff resolution logic.

If multiple staff members match the same name:

- Stop automatic assignment.
- Return multiple candidate matches.
- Ask the user to select the intended staff member.

Do not silently assign a task to the first matching record.

---

## Attachments Are Sent Incorrectly

Check:

- Attachment type detection
- Telegram file identifiers
- Media group construction
- Caption placement
- Notification message ordering
- Button placement

Test all attachment combinations after changes.

---

# 11. Recommended Developer Practices

## Keep Changes Localized

When fixing a bug:

- Identify the exact failure point.
- Understand the current pipeline.
- Preserve working behavior.
- Avoid unnecessary rewrites.

This is particularly important for:

- Telegram bot handlers
- Task creation pipelines
- Staff resolution
- Notification delivery

---

## Maintain Existing Frontend Design

When converting or modifying admin pages:

- Keep the current visual design.
- Reuse shared layouts and components.
- Replace raw data with controller-provided data.
- Keep Blade templates clean.
- Avoid unnecessary visual regressions.

Functional changes should not accidentally redesign the interface.

---

## Validate Both Backend and Frontend

A feature can fail because of:

- Route generation
- Controller logic
- Blade markup
- JavaScript
- Event handling
- Permission middleware
- Database data
- Telegram callback logic

Debug the complete request and interaction path.

---

# 12. Deployment Checklist

Before production deployment:

- [ ] Run automated tests.
- [ ] Test task creation.
- [ ] Test staff resolution.
- [ ] Test duplicate staff names.
- [ ] Test admin task navigation.
- [ ] Test Uzbek status labels.
- [ ] Test status and priority colors.
- [ ] Test Telegram notifications.
- [ ] Test notification buttons.
- [ ] Test media and attachment delivery.
- [ ] Verify environment variables.
- [ ] Verify database migrations.
- [ ] Verify queue workers, if used.
- [ ] Disable debug mode in production.
- [ ] Secure production credentials.

Typical production commands may include:

```bash
php artisan migrate --force
php artisan optimize
```

Run additional deployment commands according to the project's hosting and infrastructure configuration.

---

# 13. Quick Reference

## Admin

Use the admin panel to:

- Manage staff
- Review tasks
- Track statuses
- Manage permissions
- Monitor assignments

## Assignor

Use the system to:

- Create tasks
- Select assignees
- Add priority
- Attach files
- Monitor task progress

## Assignee

Use Telegram to:

- Receive task notifications
- Review task details
- Press **Qabul qilish**
- Press **Qaytarish** when necessary
- Complete assigned work

## Developer

Maintain:

- Laravel backend
- Blade admin panel
- Database
- Permissions
- Telegram bot
- Notification system
- Task workflow

---

# 14. Project Principle

The central principle of this project is:

> A task should move reliably from assignment to notification, acceptance, execution, and completion, while administrators and authorized users can monitor the entire workflow.

Every feature change should preserve that end-to-end workflow.
