const { app, BrowserWindow } = require('electron');
const path = require('path');
const { spawn } = require('child_process');
const fs = require('fs');

let mainWindow;
let phpServer;

function createWindow(port) {
  mainWindow = new BrowserWindow({
    width: 1200,
    height: 800,
    title: "ConRQ App",
    webPreferences: {
      nodeIntegration: false,
      contextIsolation: true
    }
  });

  mainWindow.loadURL(`http://127.0.0.1:${port}`);
  mainWindow.on('closed', () => {
    mainWindow = null;
  });
}

app.whenReady().then(async () => {
  const port = 8080;
  
  const isPackaged = app.isPackaged;
  const resourcePath = process.resourcesPath;
  
  // The PHP binary is kept outside the ASAR archive for execution
  const phpBin = isPackaged 
    ? path.join(resourcePath, 'php-bin', 'php') 
    : path.join(__dirname, 'php-bin', 'php');

  // The PHP source is securely bundled inside the encrypted/read-only app.asar
  const phpSrc = __dirname;
  
  // Set database path to macOS Application Support directory to bypass read-only ASAR and sandbox restrictions
  const userDataPath = app.getPath('userData');
  process.env.CONRQ_DB_PATH = path.join(userDataPath, 'conrq_database.sqlite');
  
  // Ensure the user data directory exists
  if (!fs.existsSync(userDataPath)) {
    fs.mkdirSync(userDataPath, { recursive: true });
  }

  // Start the internal PHP server
  phpServer = spawn(phpBin, ['-S', `127.0.0.1:${port}`, '-t', phpSrc]);

  phpServer.stdout.on('data', (data) => console.log(`PHP: ${data}`));
  phpServer.stderr.on('data', (data) => console.error(`PHP Error: ${data}`));

  // Delay window creation slightly to ensure PHP server binds to port
  setTimeout(() => createWindow(port), 1000);
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
  if (phpServer) phpServer.kill();
});

app.on('will-quit', () => {
  if (phpServer) phpServer.kill();
});
