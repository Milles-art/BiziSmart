import {
  createTheme,
  StyledEngineProvider,
  ThemeProvider,
} from "@mui/material";
import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { Provider } from "react-redux";
import App from "./App.tsx";
import "./index.css";
import { reduxStore } from "./store/redux";
const theme = createTheme({
  palette: { primary: { main: "#10b981" }, background: { default: "#f1f5f9" } },
  typography: { fontFamily: "inherit" },
  shape: { borderRadius: 12 },
  components: { MuiDialog: { styleOverrides: { paper: { borderRadius:16, border:'1px solid #e2e8f0', boxShadow:'0 24px 64px rgb(15 23 42 / 18%)' } } } },
});

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <Provider store={reduxStore}>
      <StyledEngineProvider injectFirst>
        <ThemeProvider theme={theme}>
          <App />
        </ThemeProvider>
      </StyledEngineProvider>
    </Provider>
  </StrictMode>,
);
