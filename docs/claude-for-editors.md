# Using Claude with your Craft site

A short guide for editors. Once your site's administrator has turned on the Claude connector, you can ask Claude to find, read and write content on your Craft site, from Claude on the web, the Desktop app or your phone.

## Before you start

You need two things from your site's administrator:

- **The connector URL.** It looks like `https://cms.your-site.com/mcp`.
- **Permission to connect apps.** Your Craft account needs the "Manage their own MCP tokens" permission. If you are missing it, the approval screen below tells you so.

You also need a Claude account. Free, Pro and Max accounts can add the connector themselves; on Team and Enterprise an Owner adds it once for everyone (see below).

## Connect

1. In Claude, open **Customize → Connectors** and choose **Add custom connector**.
2. Give it a name you will recognise, for example the name of your site, and paste the connector URL. Leave everything else empty.
3. Click **Add**, then **Connect**.
4. Your Craft login page opens. Log in with your usual Craft account.
5. Craft shows which app wants to connect and asks what it may do. Pick one and click **Approve**:

| Choice | What Claude can do |
|---|---|
| **Readonly** | Look things up: entries, sections, settings. It cannot change anything. |
| **Content** | Look things up, and write entries. Anything it writes follows your own Craft permissions. Usually the right choice. |
| **Full** | Everything, including technical tools. Only offered to administrators; only pick it if you know you need it. |

You are sent back to Claude, and the connector shows as connected.

**On a Team or Enterprise plan**, an Owner adds the connector once under **Organization settings → Connectors**. You then find it under **Customize → Connectors**, marked **Custom**, and click **Connect** to sign in with your own Craft account (steps 4 and 5 above).

## Use it

Ask Claude in plain language, for example:

- "Which blog posts did we publish this month?"
- "Find the page about our pricing and summarise it."
- "Write a draft news item about our new office, in the News section."

What Claude writes is saved as a **draft** by default. Nothing goes live until someone publishes it. Each draft comes with two links:

- **a preview link**, which shows the draft as it will look on the site, without logging in. It stops working after a day.
- **a control panel link**, which opens the draft in Craft so you can review, edit and publish it yourself.

You can turn the connector on or off per conversation with the **+** button in the chat box, under **Connectors**.

## Disconnect

In Craft, go to **My Account → MCP Tokens**. Under **Connected Apps**, click **Disconnect** next to Claude. It loses access straight away. To use it again, connect again from Claude.

## If something goes wrong

- **The approval screen says you do not have permission:** ask your administrator for the "Manage their own MCP tokens" permission.
- **Claude says it cannot reach the server, or that registration failed:** the site is not set up for the connector yet. Send your administrator the [setup guide](http-transport.md#connecting-claude-web-desktop-and-mobile).
- **Claude stopped working after it worked before:** your connection may have been disconnected, or your permissions changed. Remove the connector in Claude and connect again.
