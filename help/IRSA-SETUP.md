# IRSA Setup for GitHub Actions on EKS

This guide explains how to configure IAM Roles for Service Accounts (IRSA) to allow GitHub Actions runners running on Amazon EKS to push Docker images to Amazon ECR.

## Overview

The Docker build and push workflow (`.github/workflows/docker-build-push.yml`) requires authentication to Amazon ECR. When using self-hosted GitHub Actions runners on EKS, the recommended approach is to use IRSA (IAM Roles for Service Accounts) instead of long-lived AWS credentials.

## Prerequisites

- Amazon EKS cluster running
- Self-hosted GitHub Actions runner deployed on the EKS cluster
- Amazon ECR repository created: `melhorias-habitacionais`
- AWS Account ID: `298680963177`
- AWS CLI and `eksctl` installed (for setup)

## Architecture

```
┌─────────────────────────┐
│ GitHub Actions Workflow │
│  (self-hosted runner)   │
└───────────┬─────────────┘
            │
            │ assumes role via IRSA
            ▼
┌─────────────────────────┐
│  EKS Service Account    │
│  with IAM Role ARN      │
│  annotation             │
└───────────┬─────────────┘
            │
            │ web identity token
            ▼
┌─────────────────────────┐
│  IAM Role for ECR       │
│  (trust policy allows   │
│   service account)      │
└───────────┬─────────────┘
            │
            │ permissions
            ▼
┌─────────────────────────────────┐
│  Amazon ECR Repository          │
│  (melhorias-habitacionais)      │
└─────────────────────────────────┘
```

## Step-by-Step Setup

### 1. Enable IRSA on your EKS Cluster

If not already enabled, associate an OIDC provider with your cluster:

```bash
# Replace with your cluster name and region
export CLUSTER_NAME="your-cluster-name"
export AWS_REGION="us-east-1"

# Create OIDC provider for the cluster
eksctl utils associate-iam-oidc-provider \
  --cluster=$CLUSTER_NAME \
  --region=$AWS_REGION \
  --approve
```

Verify the OIDC provider:

```bash
aws eks describe-cluster \
  --name $CLUSTER_NAME \
  --region $AWS_REGION \
  --query "cluster.identity.oidc.issuer" \
  --output text
```

### 2. Create IAM Policy for ECR Access

Create a policy that allows push access to the ECR repository:

```bash
cat > ecr-push-policy.json <<EOF
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "ecr:GetAuthorizationToken"
      ],
      "Resource": "*"
    },
    {
      "Effect": "Allow",
      "Action": [
        "ecr:BatchCheckLayerAvailability",
        "ecr:GetDownloadUrlForLayer",
        "ecr:GetRepositoryPolicy",
        "ecr:DescribeRepositories",
        "ecr:ListImages",
        "ecr:DescribeImages",
        "ecr:BatchGetImage",
        "ecr:InitiateLayerUpload",
        "ecr:UploadLayerPart",
        "ecr:CompleteLayerUpload",
        "ecr:PutImage"
      ],
      "Resource": "arn:aws:ecr:us-east-1:298680963177:repository/melhorias-habitacionais"
    }
  ]
}
EOF

# Create the policy
aws iam create-policy \
  --policy-name GitHubActionsECRPushPolicy \
  --policy-document file://ecr-push-policy.json
```

Note the Policy ARN from the output (you'll need it in the next step).

### 3. Create IAM Role with Trust Policy

Create an IAM role that can be assumed by the Kubernetes service account:

```bash
# Replace with your account ID and OIDC provider ID
export AWS_ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
export OIDC_PROVIDER=$(aws eks describe-cluster \
  --name $CLUSTER_NAME \
  --region $AWS_REGION \
  --query "cluster.identity.oidc.issuer" \
  --output text | sed -e 's|^https://||')

# Create trust policy
cat > trust-policy.json <<EOF
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Principal": {
        "Federated": "arn:aws:iam::${AWS_ACCOUNT_ID}:oidc-provider/${OIDC_PROVIDER}"
      },
      "Action": "sts:AssumeRoleWithWebIdentity",
      "Condition": {
        "StringEquals": {
          "${OIDC_PROVIDER}:sub": "system:serviceaccount:github-actions:github-runner",
          "${OIDC_PROVIDER}:aud": "sts.amazonaws.com"
        }
      }
    }
  ]
}
EOF

# Create the role
aws iam create-role \
  --role-name GitHubActionsECRPushRole \
  --assume-role-policy-document file://trust-policy.json \
  --description "Role for GitHub Actions runners to push to ECR"

# Attach the policy to the role
aws iam attach-role-policy \
  --role-name GitHubActionsECRPushRole \
  --policy-arn arn:aws:iam::${AWS_ACCOUNT_ID}:policy/GitHubActionsECRPushPolicy
```

**Note:** The trust policy assumes:
- **Namespace:** `github-actions`
- **Service Account:** `github-runner`

Adjust these values if your GitHub Actions runner uses different namespace/service account.

### 4. Create Kubernetes Service Account

Create a service account in your EKS cluster with the IAM role annotation:

```bash
export ROLE_ARN="arn:aws:iam::${AWS_ACCOUNT_ID}:role/GitHubActionsECRPushRole"

cat > github-runner-sa.yaml <<EOF
apiVersion: v1
kind: ServiceAccount
metadata:
  name: github-runner
  namespace: github-actions
  annotations:
    eks.amazonaws.com/role-arn: ${ROLE_ARN}
EOF

# Create the namespace if it doesn't exist
kubectl create namespace github-actions --dry-run=client -o yaml | kubectl apply -f -

# Apply the service account
kubectl apply -f github-runner-sa.yaml
```

### 5. Update GitHub Actions Runner

Ensure your GitHub Actions runner deployment uses the service account:

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: github-runner
  namespace: github-actions
spec:
  template:
    spec:
      serviceAccountName: github-runner  # Important!
      containers:
      - name: runner
        image: your-github-runner-image
        # ... rest of your runner configuration
```

Apply the updated deployment:

```bash
kubectl apply -f github-runner-deployment.yaml
```

### 6. Add GitHub Secret

Add the IAM Role ARN as a GitHub repository secret:

1. Go to your repository on GitHub
2. Navigate to **Settings** → **Secrets and variables** → **Actions**
3. Click **New repository secret**
4. Name: `AWS_ROLE_ARN`
5. Value: `arn:aws:iam::YOUR_ACCOUNT_ID:role/GitHubActionsECRPushRole`
6. Click **Add secret**

## Verification

### Test IRSA Configuration

Create a test pod to verify the service account can assume the IAM role:

```bash
kubectl run -it --rm debug \
  --image=amazon/aws-cli \
  --serviceaccount=github-runner \
  --namespace=github-actions \
  -- sts get-caller-identity
```

Expected output should show the IAM role ARN:

```json
{
    "UserId": "AROAXXXXXXXXXXXXXXXXX:botocore-session-1234567890",
    "Account": "123456789012",
    "Arn": "arn:aws:sts::123456789012:assumed-role/GitHubActionsECRPushRole/botocore-session-1234567890"
}
```

### Test ECR Push

Test pushing to ECR from within the cluster:

```bash
kubectl run -it --rm ecr-test \
  --image=amazon/aws-cli \
  --serviceaccount=github-runner \
  --namespace=github-actions \
  -- ecr get-login-password --region us-east-1
```

If successful, you should see an authentication token (long base64 string).

### Trigger the Workflow

Push a commit to the `main` branch and verify the workflow runs successfully:

```bash
git add .
git commit -m "Test ECR push workflow"
git push origin main
```

Check the Actions tab in your GitHub repository to monitor the workflow execution.

## Troubleshooting

### Error: "An error occurred (AccessDeniedException) when calling the GetAuthorizationToken operation"

**Cause:** The IAM role doesn't have `ecr:GetAuthorizationToken` permission.

**Solution:** Verify the IAM policy includes:
```json
{
  "Effect": "Allow",
  "Action": ["ecr:GetAuthorizationToken"],
  "Resource": "*"
}
```

### Error: "WebIdentityErr: failed to retrieve credentials"

**Cause:** Trust policy doesn't match the service account or OIDC provider.

**Solution:**
1. Verify the service account name and namespace in the trust policy
2. Ensure the OIDC provider is correctly associated with the cluster
3. Check the service account annotation: `kubectl describe sa github-runner -n github-actions`

### Error: "denied: Your authorization token has expired"

**Cause:** The ECR login token expired (valid for 12 hours).

**Solution:** The workflow automatically refreshes the token on each run. If running long builds, consider splitting into separate jobs.

### Error: "Runner not found" or workflow doesn't start

**Cause:** Self-hosted runner is not properly connected or not running.

**Solution:**
1. Check runner status: `kubectl get pods -n github-actions`
2. Check runner logs: `kubectl logs -n github-actions -l app=github-runner`
3. Verify runner is registered in GitHub: Settings → Actions → Runners

## Security Best Practices

1. **Least Privilege:** Only grant ECR permissions for the specific repository (`melhorias-habitacionais`)
2. **Namespace Isolation:** Use dedicated namespace for GitHub runners
3. **Regular Rotation:** Periodically review and update IAM policies
4. **Audit Logging:** Enable CloudTrail to audit ECR access
5. **Image Scanning:** Enable ECR image scanning for vulnerabilities

## Cleanup (Optional)

To remove IRSA configuration:

```bash
# Delete Kubernetes service account
kubectl delete serviceaccount github-runner -n github-actions

# Detach policy from role
aws iam detach-role-policy \
  --role-name GitHubActionsECRPushRole \
  --policy-arn arn:aws:iam::${AWS_ACCOUNT_ID}:policy/GitHubActionsECRPushPolicy

# Delete IAM role
aws iam delete-role --role-name GitHubActionsECRPushRole

# Delete IAM policy
aws iam delete-policy \
  --policy-arn arn:aws:iam::${AWS_ACCOUNT_ID}:policy/GitHubActionsECRPushPolicy
```

## Extending IRSA for S3 Access

The application also supports S3 for file storage (see [S3-STORAGE.md](./S3-STORAGE.md) for details). To enable S3 access via IRSA:

### 1. Create S3 Access Policy

```bash
cat > s3-access-policy.json <<EOF
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
  --policy-name PVRApplicationS3Policy \
  --policy-document file://s3-access-policy.json
```

### 2. Create IAM Role for Application Service Account

```bash
# Get cluster OIDC provider
export OIDC_PROVIDER=$(aws eks describe-cluster \
  --name $CLUSTER_NAME \
  --region $AWS_REGION \
  --query "cluster.identity.oidc.issuer" \
  --output text | sed -e 's|^https://||')

# Create trust policy for application service account
cat > app-trust-policy.json <<EOF
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Principal": {
        "Federated": "arn:aws:iam::${AWS_ACCOUNT_ID}:oidc-provider/${OIDC_PROVIDER}"
      },
      "Action": "sts:AssumeRoleWithWebIdentity",
      "Condition": {
        "StringEquals": {
          "${OIDC_PROVIDER}:sub": "system:serviceaccount:default:pvr-service-account",
          "${OIDC_PROVIDER}:aud": "sts.amazonaws.com"
        }
      }
    }
  ]
}
EOF

# Create the role
aws iam create-role \
  --role-name PVRApplicationS3Role \
  --assume-role-policy-document file://app-trust-policy.json \
  --description "Role for PVR application to access S3"

# Attach the S3 policy
aws iam attach-role-policy \
  --role-name PVRApplicationS3Role \
  --policy-arn arn:aws:iam::${AWS_ACCOUNT_ID}:policy/PVRApplicationS3Policy
```

### 3. Annotate Application Service Account

Update `helm/pvr/values.yaml`:

```yaml
serviceAccount:
  create: true
  annotations:
    eks.amazonaws.com/role-arn: arn:aws:iam::298680963177:role/PVRApplicationS3Role
```

Or create the service account manually:

```bash
kubectl annotate serviceaccount pvr-service-account \
  -n default \
  eks.amazonaws.com/role-arn=arn:aws:iam::${AWS_ACCOUNT_ID}:role/PVRApplicationS3Role
```

### 4. Verify S3 Access

Test from within a pod:

```bash
# Get the pod name
export PHP_POD=$(kubectl get pods -l app.kubernetes.io/name=pvr -o jsonpath='{.items[0].metadata.name}')

# Test S3 access
kubectl exec -it $PHP_POD -- aws s3 ls s3://pvr-uploads/

# Test credentials
kubectl exec -it $PHP_POD -- aws sts get-caller-identity
```

Expected output should show the IAM role ARN.

## References

- [EKS IRSA Documentation](https://docs.aws.amazon.com/eks/latest/userguide/iam-roles-for-service-accounts.html)
- [FluxCD Sortable Image Tags Guide](https://fluxcd.io/flux/guides/sortable-image-tags/)
- [ECR User Guide](https://docs.aws.amazon.com/AmazonECR/latest/userguide/)
- [GitHub Actions Self-Hosted Runners](https://docs.github.com/en/actions/hosting-your-own-runners)
- [S3 Storage Configuration](./S3-STORAGE.md)
