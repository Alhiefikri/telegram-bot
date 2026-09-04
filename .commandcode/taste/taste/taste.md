# Taste
- Follows a complete end-of-task loop for verified fixes: commit the change, push to the main branch, then close the related issue (with a summary comment) — all in one go, without waiting for a separate reminder to close the issue. Explicitly prompts the agent to do this when ready ("silahkan commit dan push lalu close issue terkait"). Confidence: 0.8
- Communicates in Bahasa Indonesia (Indonesian); expects the agent to respond in Indonesian as well. Confidence: 0.9
- Steers sessions with terse prompts ("oke silahkan commit dan push lalu close issue terkait", "/cmd -yolo", "resume", "continue") and expects the agent to work autonomously through the full task loop without intermediate check-ins. Confidence: 0.65
- When asked to explain an issue ("masalahnya apa sih, coba jelasin"), prefers a plain-language walkthrough — root cause first, then the consequences (with concrete real-world impact), then the fix — using short, structured sections and avoiding jargon, rather than a purely technical changelog recap. Confidence: 0.55

