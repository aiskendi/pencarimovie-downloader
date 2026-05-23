# PencariMovie Downloader

Local Telegram file downloader.

## Warning

- Use only on a trusted private network.
- Do not expose your bot token or config files.

## Download and run

### Windows

```powershell
wget https://github.com/aiskendi/pencarimovie-downloader/releases/download/v1.0.0/pencarimovie-downloader-windows-x86_64.zip -OutFile pencarimovie.zip; Expand-Archive pencarimovie.zip -DestinationPath . -Force; .\start.bat
```

### Linux / MAC / TERMUX (Android)

```bash
mkdir pencarimovie-downloader && cd pencarimovie-downloader && wget https://github.com/aiskendi/pencarimovie-downloader/releases/download/v1.0.0/pencarimovie-downloader-linux-x86_64.tar.gz -O pencarimovie.tar.gz && tar -xzf pencarimovie.tar.gz && bash start.sh
```

### On Browser

Open the local app:

```text
http://127.0.0.1:8088
```

On first launch, the app shows the bot-token setup screen before the search page.

1. Paste your Telegram bot token in the **Bot Token** field.
2. Click **Validate & Continue**.
3. Wait until the token is saved locally and synced to PencariMovie.
4. After validation succeeds, the search page opens.
5. Search a title, then click **Download** or **Resolve Link**.

If the app opens the search page without asking for a bot token, click **Reset** and refresh the browser. The app must have a bot token saved in `storage/config.json` before downloads can work.

Do not share your local URL, `storage/config.json`, or bot token with other people.
