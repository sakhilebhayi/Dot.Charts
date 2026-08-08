from fastapi import FastAPI

app = FastAPI(title="Dot.Charts Analytics Service")


@app.get("/health")
def health():
    return {"status": "ok"}
