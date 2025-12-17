# AWS S3 Storage Configuration

This document describes how to configure and use AWS S3 for file storage in the Aurora/MCM application.

## Overview

The application supports two storage adapters:
- **Local Filesystem** - For development and testing
- **AWS S3** - For production deployments (recommended for Kubernetes)

The storage layer uses [Flysystem](https://flysystem.thephpleague.com/) which provides a unified API regardless of the storage backend. This means **no code changes** are needed when switching between local and S3 storage.

## Why S3 for Kubernetes?

In the current Kubernetes deployment, files stored locally have critical issues:
- ❌ Files are lost when pods restart
- ❌ Each pod has its own isolated filesystem
- ❌ Horizontal scaling doesn't work (files not shared between pods)
- ❌ No backup or disaster recovery

**S3 solves all these problems:**
- ✅ Centralized storage shared across all pods
- ✅ Files persist across pod restarts and redeployments
- ✅ Built-in redundancy and high availability
- ✅ Automatic backups and versioning
- ✅ CDN integration with CloudFront

## Architecture

```
Request → Controller → Service → FileService → Flysystem → Storage Backend
                                                                   ↓
                                                          Local or S3
```

The application code is abstracted from the storage implementation through Flysystem's `FilesystemOperator` interface.

## Configuration

### 1. Environment Variables

Storage behavior is controlled by environment variables:

```bash
# Storage adapter selection
STORAGE_ADAPTER=s3  # or 'local' for filesystem

# AWS S3 Configuration (required when STORAGE_ADAPTER=s3)
AWS_REGION=us-east-1
AWS_S3_BUCKET=pvr-uploads
AWS_S3_PREFIX=  # Optional prefix for all files (e.g., 'prod/' or 'staging/')

# AWS Credentials (optional - see Authentication section)
# AWS_ACCESS_KEY_ID=your-access-key
# AWS_SECRET_ACCESS_KEY=your-secret-key
```

### 2. Helm Chart Configuration

**Production values** (`helm/pvr/values.yaml`):

```yaml
php:
  storage:
    adapter: "s3"
    s3:
      region: "us-east-1"
      bucket: "pvr-uploads"
      prefix: ""
```

**Development values** (`skaffold-values.yaml`):

```yaml
php:
  storage:
    adapter: "local"  # Uses local filesystem in development
```

### 3. Service Account Annotations (IRSA)

For production deployments using IRSA (recommended), annotate the service account:

```yaml
serviceAccount:
  create: true
  annotations:
    eks.amazonaws.com/role-arn: arn:aws:iam::298680963177:role/pvr-s3-access-role
```

## Authentication Methods

The application supports multiple AWS authentication methods (in order of precedence):

### 1. IRSA (Recommended for EKS)

**IAM Roles for Service Accounts** - Most secure method for Kubernetes.

**How it works:**
- Kubernetes Service Account is annotated with an IAM Role ARN
- AWS STS automatically injects temporary credentials into the pod
- No static credentials needed in config or secrets

**Setup:**

```bash
# 1. Create IAM policy with S3 permissions
cat > s3-policy.json <<EOF
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "s3:PutObject",
        "s3:GetObject",
        "s3:DeleteObject",
        "s3:ListBucket"
      ],
      "Resource": [
        "arn:aws:s3:::pvr-uploads/*",
        "arn:aws:s3:::pvr-uploads"
      ]
    }
  ]
}
EOF

aws iam create-policy \
  --policy-name pvr-s3-access-policy \
  --policy-document file://s3-policy.json

# 2. Create IAM role for service account
eksctl create iamserviceaccount \
  --name pvr-service-account \
  --namespace default \
  --cluster your-cluster-name \
  --attach-policy-arn arn:aws:iam::298680963177:policy/pvr-s3-access-policy \
  --approve

# 3. Update Helm values
# The service account annotation is automatically added by eksctl
```

### 2. Environment Variables

For local development or non-EKS deployments:

```bash
export AWS_ACCESS_KEY_ID=your-access-key
export AWS_SECRET_ACCESS_KEY=your-secret-key
export AWS_REGION=us-east-1
```

### 3. EC2 Instance Profile

For deployments on EC2 instances, attach an IAM role to the instance.

### 4. AWS Credentials File

The SDK will read from `~/.aws/credentials` if present.

## S3 Bucket Setup

### Create the Bucket

```bash
# Create bucket
aws s3 mb s3://pvr-uploads --region us-east-1

# Enable versioning (recommended for backup)
aws s3api put-bucket-versioning \
  --bucket pvr-uploads \
  --versioning-configuration Status=Enabled

# Enable server-side encryption
aws s3api put-bucket-encryption \
  --bucket pvr-uploads \
  --server-side-encryption-configuration '{
    "Rules": [{
      "ApplyServerSideEncryptionByDefault": {
        "SSEAlgorithm": "AES256"
      }
    }]
  }'
```

### Configure CORS (if needed)

If you need direct browser uploads:

```bash
aws s3api put-bucket-cors \
  --bucket pvr-uploads \
  --cors-configuration '{
    "CORSRules": [{
      "AllowedOrigins": ["https://your-domain.com"],
      "AllowedMethods": ["GET", "PUT", "POST", "DELETE"],
      "AllowedHeaders": ["*"],
      "MaxAgeSeconds": 3000
    }]
  }'
```

### Bucket Lifecycle Policy (Optional)

Clean up old files automatically:

```bash
cat > lifecycle-policy.json <<EOF
{
  "Rules": [{
    "Id": "DeleteOldVersions",
    "Status": "Enabled",
    "NoncurrentVersionExpiration": {
      "NoncurrentDays": 30
    }
  }]
}
EOF

aws s3api put-bucket-lifecycle-configuration \
  --bucket pvr-uploads \
  --lifecycle-configuration file://lifecycle-policy.json
```

## CloudFront CDN (Optional)

For better performance serving static files:

```bash
# Create CloudFront distribution
aws cloudfront create-distribution \
  --origin-domain-name pvr-uploads.s3.us-east-1.amazonaws.com \
  --default-root-object index.html

# Update environment variable to use CloudFront URL
STORAGE_IMAGES_BASE_URL=https://d1234567890.cloudfront.net
```

## Development Workflow

### Local Development with Local Storage

```bash
# Use local filesystem (default in skaffold-values.yaml)
make up
```

Files are stored in `assets/uploads/` directory.

### Local Development with S3

To test S3 integration locally:

```bash
# 1. Set AWS credentials
export AWS_ACCESS_KEY_ID=your-key
export AWS_SECRET_ACCESS_KEY=your-secret

# 2. Update skaffold-values.yaml
php:
  storage:
    adapter: "s3"
    s3:
      region: "us-east-1"
      bucket: "pvr-uploads-dev"
      prefix: "dev/"

# 3. Start development
make up
```

## Testing

Run the FileService tests to validate storage functionality:

```bash
# Run all tests
make tests_back

# Run only FileService tests
make tests_back filename=tests/Functional/Services/FileServiceTest.php
```

Tests use the storage adapter configured in your environment.

## Migration from Local to S3

If you have existing files in local storage and want to migrate to S3:

```bash
# Sync local files to S3
aws s3 sync assets/uploads/ s3://pvr-uploads/uploads/ \
  --exclude "*.gitkeep" \
  --acl public-read

# Verify files were uploaded
aws s3 ls s3://pvr-uploads/uploads/ --recursive --human-readable
```

## Troubleshooting

### Files not uploading to S3

**Check credentials:**
```bash
# Inside pod
aws s3 ls s3://pvr-uploads/
```

**Check IAM permissions:**
```bash
# Test S3 access
aws s3 cp test.txt s3://pvr-uploads/test.txt
```

**Check service account annotations:**
```bash
kubectl describe serviceaccount pvr-service-account
# Should show eks.amazonaws.com/role-arn annotation
```

### "Access Denied" errors

- Verify IAM policy includes all required actions: `s3:PutObject`, `s3:GetObject`, `s3:DeleteObject`, `s3:ListBucket`
- Verify resource ARNs in policy match your bucket name
- Check bucket policy doesn't block access
- Verify IRSA trust relationship is correct

### Files upload but URLs return 403

- Check S3 bucket ACL settings
- Verify `visibility: public` in `flysystem.yaml`
- Consider using CloudFront signed URLs for private files

### Performance issues

- Enable CloudFront CDN for static file delivery
- Use S3 Transfer Acceleration for large files
- Consider multi-part uploads for files > 5MB (future enhancement)

## File URLs

### With Local Storage

```
http://localhost:8080/assets/uploads/spaces/images/profile/abc123.jpg
```

### With S3

```
https://pvr-uploads.s3.us-east-1.amazonaws.com/uploads/spaces/images/profile/abc123.jpg
```

### With CloudFront

```
https://d1234567890.cloudfront.net/uploads/spaces/images/profile/abc123.jpg
```

The application automatically generates the correct URL based on `STORAGE_IMAGES_BASE_URL`.

## Security Best Practices

1. ✅ **Use IRSA** - Never store static AWS credentials in code or config
2. ✅ **Enable encryption** - Use S3 server-side encryption (SSE-S3 or SSE-KMS)
3. ✅ **Limit IAM permissions** - Grant only required S3 actions
4. ✅ **Enable versioning** - Protect against accidental deletions
5. ✅ **Block public access** - Use CloudFront with signed URLs for private files
6. ✅ **Enable logging** - Monitor S3 access logs
7. ✅ **Use VPC endpoints** - Route S3 traffic privately within AWS

## Cost Optimization

- Use S3 Intelligent-Tiering for automatic cost optimization
- Enable lifecycle policies to delete old versions
- Use CloudFront to reduce S3 GET request costs
- Monitor costs with AWS Cost Explorer

## Monitoring

Track S3 usage and performance:

```bash
# Enable S3 access logging
aws s3api put-bucket-logging \
  --bucket pvr-uploads \
  --bucket-logging-status file://logging.json

# Enable CloudWatch metrics
aws s3api put-bucket-metrics-configuration \
  --bucket pvr-uploads \
  --id EntireBucket \
  --metrics-configuration file://metrics.json
```

## References

- [Flysystem Documentation](https://flysystem.thephpleague.com/)
- [AWS S3 Documentation](https://docs.aws.amazon.com/s3/)
- [IRSA Documentation](https://docs.aws.amazon.com/eks/latest/userguide/iam-roles-for-service-accounts.html)
- [Project Architecture](./README.md)
