# Fix Railway Image Size Limit (8.2 GB → ~1-2 GB)

## Problem

Railway has a 4.0 GB image size limit on free tier. Your image is 8.2 GB because PyTorch with CUDA support is huge.

## Solution: Use CPU-Only PyTorch

Since Railway doesn't have GPUs, we should use CPU-only PyTorch which is much smaller (~500MB vs ~3GB+).

## What I Fixed

I've updated `ai_service/requirements.txt` to use CPU-only PyTorch:
- Added `--extra-index-url https://download.pytorch.org/whl/cpu` at the top
- This tells pip to install PyTorch from the CPU-only repository
- CPU-only PyTorch is ~500MB instead of ~3GB+ with CUDA

## Expected Size Reduction

- **Before**: 8.2 GB (PyTorch with CUDA + all CUDA libraries)
- **After**: ~1-2 GB (CPU-only PyTorch, no CUDA libraries)

## Next Steps

1. **Commit and push:**
   ```bash
   git add ai_service/requirements.txt
   git commit -m "Use CPU-only PyTorch to reduce image size for Railway"
   git push
   ```

2. **Railway will redeploy** with the smaller image

3. **Verify the build** - should be under 4 GB now

## Why This Works

- Railway free tier doesn't have GPUs anyway
- Your code already uses CPU (`device=-1` in app.py)
- CPU-only PyTorch is much smaller
- Performance is the same since you're not using GPU

## Alternative: If Still Too Large

If the image is still too large, you can:

1. **Use a smaller model** (already using `distilgpt2`)
2. **Remove unused dependencies**
3. **Use Railway's paid plan** (if needed)

But CPU-only PyTorch should fix it!

