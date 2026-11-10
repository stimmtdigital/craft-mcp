# Using Claude with your Craft site

A short guide for editors. Once your site's administrator has turned on Craft's MCP server, you can ask Claude to find, read and write content on your Craft site, from Claude on the web, the Desktop app or your phone.

## Before you start

You need two things from your site's administrator:

- **The connector URL.** It looks like `https://cms.your-site.com/admin/ai/auth`.
- **A Craft account** you can log in with. If the URL contains your control panel address (such as `/admin/`), the account also needs access to the control panel.

You also need a Claude account. Free, Pro and Max accounts can add the connector themselves; on Team and Enterprise an Owner adds it once for everyone (see below).

## Connect

1. In Claude, open **Settings → Connectors** and choose **Add custom connector**.
2. Give it a name you will recognise, for example the name of your site, and paste the connector URL. Leave everything else empty.
3. Click **Add**, then **Connect**.
4. Your Craft login page opens. Log in with your usual Craft account.
5. Craft shows which app wants to access your account and asks what it may do. Pick one and click **Authorize**:

| Choice | What Claude can do |
|---|---|
| **Read only** | Look things up: entries, assets, categories. It cannot change anything. |
| **Read and write** | Look things up and create, edit and delete content. Anything it does follows your own Craft permissions. Usually the right choice. |

Administrators also see **Full access**, which adds technical tools. Only pick it if you know you need it.

You are sent back to Claude, and the connector shows as connected.

**On a Team or Enterprise plan**, an Owner adds the connector once in the organisation's connector settings. You then find it among your connectors, marked **Custom**, and click **Connect** to sign in with your own Craft account (steps 4 and 5 above).

## Use it

Ask Claude in plain language, for example:

- "Which blog posts did we publish this month?"
- "How many news items does each category have?"
- "Find the page about our pricing and summarise it."
- "Write a draft news item about our new office, in the News section."

With **Read and write**, Claude can change live content as well as drafts. If you want to check its work first, say so: ask for **a draft**, and nothing goes live until someone publishes it.

To check a draft:

- **Ask Claude for a preview link.** It shows the draft as it will look on the site, without logging in. It stops working after a while, a day unless your administrator set it differently.
- **Open the entry in the Craft control panel**, where you can review, edit and publish the draft yourself.

You can turn the connector on or off per conversation with the **+** button in the chat box, under **Connectors**.

## Disconnect

In Craft, go to **My Account → Tokens**. Find Claude in the list and click **Revoke**. It loses access straight away. To use it again, connect again from Claude.

## If something goes wrong

- **You see a login page or an error instead of the approval screen, or Claude says it cannot reach the server:** the site is not set up for connectors yet. Ask your administrator to run `php craft mcp/check`; [Installation](installation.md) explains each fix.
- **Claude says it cannot do something you asked:** the access you chose may not include it. Read only cannot change anything. Disconnect, connect again and choose Read and write.
- **Claude stopped working after it worked before:** your connection may have been revoked, or your permissions changed. Remove the connector in Claude and connect again.
