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
